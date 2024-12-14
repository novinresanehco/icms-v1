<?php

namespace App\Core\Template;

use Illuminate\Support\Facades\{DB, Cache, View};
use App\Core\Security\SecurityContext;
use App\Core\Exceptions\TemplateException;

class TemplateManager
{
    private SecurityManager $security;
    private TemplateRepository $repository;
    private TemplateValidator $validator;
    private TemplateCompiler $compiler;
    private CacheManager $cache;
    private array $config;

    public function __construct(
        SecurityManager $security,
        TemplateRepository $repository,
        TemplateValidator $validator,
        TemplateCompiler $compiler,
        CacheManager $cache,
        array $config
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->validator = $validator;
        $this->compiler = $compiler;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function render(string $template, array $data, SecurityContext $context): string
    {
        return $this->security->executeCriticalOperation(function() use ($template, $data) {
            $templateData = $this->loadTemplate($template);
            $validated = $this->validator->validateData($data, $templateData->schema);
            
            return $this->cache->remember(
                $this->getCacheKey($template, $validated),
                $this->config['cache_ttl'],
                fn() => $this->doRender($templateData, $validated)
            );
        }, $context);
    }

    public function create(array $data, SecurityContext $context): Template
    {
        return $this->security->executeCriticalOperation(function() use ($data) {
            $validated = $this->validator->validateTemplate($data);
            $compiled = $this->compiler->compile($validated['content']);
            
            $templateData = array_merge($validated, [
                'compiled' => $compiled,
                'hash' => $this->generateHash($compiled)
            ]);
            
            return $this->repository->create($templateData);
        }, $context);
    }

    public function update(int $id, array $data, SecurityContext $context): Template
    {
        return $this->security->executeCriticalOperation(function() use ($id, $data) {
            $validated = $this->validator->validateTemplate($data);
            $compiled = $this->compiler->compile($validated['content']);
            
            $templateData = array_merge($validated, [
                'compiled' => $compiled,
                'hash' => $this->generateHash($compiled)
            ]);
            
            $template = $this->repository->update($id, $templateData);
            $this->cache->tags(['templates'])->flush();
            
            return $template;
        }, $context);
    }

    private function loadTemplate(string $template): Template
    {
        $templateData = $this->cache->tags(['templates'])->remember(
            "template:{$template}",
            $this->config['cache_ttl'],
            fn() => $this->repository->findByName($template)
        );

        if (!$templateData) {
            throw new TemplateException("Template not found: {$template}");
        }

        return $templateData;
    }

    private function doRender(Template $template, array $data): string
    {
        try {
            return View::make(
                'template::layout',
                array_merge($data, ['content' => $template->compiled])
            )->render();
        } catch (\Throwable $e) {
            throw new TemplateException(
                "Template rendering failed: {$e->getMessage()}"
            );
        }
    }

    private function getCacheKey(string $template, array $data): string
    {
        return "rendered_template:{$template}:" . md5(serialize($data));
    }

    private function generateHash(string $content): string
    {
        return hash('sha256', $content);
    }
}

class TemplateCompiler
{
    private array $config;
    private array $safeFilters = [
        'escape' => 'htmlspecialchars',
        'upper' => 'strtoupper',
        'lower' => 'strtolower',
        'trim' => 'trim'
    ];

    public function compile(string $template): string
    {
        // Remove potentially harmful PHP tags
        $template = preg_replace('/<\?(?:php|=)?|\?>/', '', $template);
        
        // Process template directives
        $template = $this->compileDirectives($template);
        
        // Process variables with filters
        $template = $this->compileVariables($template);
        
        return $template;
    }

    private function compileDirectives(string $template): string
    {
        $directives = [
            'if' => '/\@if\((.*?)\)/',
            'else' => '/\@else/',
            'endif' => '/\@endif/',
            'foreach' => '/\@foreach\((.*?)\)/',
            'endforeach' => '/\@endforeach/'
        ];

        foreach ($directives as $directive => $pattern) {
            $template = preg_replace_callback($pattern, function($matches) use ($directive) {
                switch ($directive) {
                    case 'if':
                        return "<?php if({$matches[1]}): ?>";
                    case 'else':
                        return "<?php else: ?>";
                    case 'endif':
                        return "<?php endif; ?>";
                    case 'foreach':
                        return "<?php foreach({$matches[1]}): ?>";
                    case 'endforeach':
                        return "<?php endforeach; ?>";
                }
            }, $template);
        }

        return $template;
    }

    private function compileVariables(string $template): string
    {
        return preg_replace_callback(
            '/\{\{\s*(.*?)\s*\}\}/',
            function($matches) {
                $variable = $this->parseVariable($matches[1]);
                return "<?php echo {$variable}; ?>";
            },
            $template
        );
    }

    private function parseVariable(string $variable): string
    {
        if (strpos($variable, '|') === false) {
            return "htmlspecialchars({$variable}, ENT_QUOTES, 'UTF-8')";
        }

        $parts = explode('|', $variable);
        $variable = trim(array_shift($parts));
        
        foreach ($parts as $filter) {
            $filter = trim($filter);
            if (!isset($this->safeFilters[$filter])) {
                continue;
            }
            $variable = "{$this->safeFilters[$filter]}({$variable})";
        }

        return $variable;
    }
}

class TemplateValidator
{
    private array $templateRules = [
        'name' => 'required|string|max:255',
        'content' => 'required|string',
        'schema' => 'required|array',
        'cache_ttl' => 'integer|min:0'
    ];

    public function validateTemplate(array $data): array
    {
        $validator = validator($data, $this->templateRules);
        
        if ($validator->fails()) {
            throw new ValidationException($validator->errors()->first());
        }
        
        return $validator->validated();
    }

    public function validateData(array $data, array $schema): array
    {
        $validator = validator($data, $schema);
        
        if ($validator->fails()) {
            throw new ValidationException($validator->errors()->first());
        }
        
        return $validator->validated();
    }
}

class TemplateRepository
{
    public function create(array $data): Template
    {
        return DB::transaction(function() use ($data) {
            $id = DB::table('templates')->insertGetId($data);
            return $this->find($id);
        });
    }

    public function update(int $id, array $data): Template
    {
        return DB::transaction(function() use ($id, $data) {
            $updated = DB::table('templates')
                ->where('id', $id)
                ->update($data);
                
            if (!$updated) {
                throw new TemplateException("Template update failed: {$id}");
            }
            
            return $this->find($id);
        });
    }

    public function find(int $id): ?Template
    {
        $data = DB::table('templates')->find($id);
        return $data ? new Template($data) : null;
    }

    public function findByName(string $name): ?Template
    {
        $data = DB::table('templates')->where('name', $name)->first();
        return $data ? new Template($data) : null;
    }
}

class Template
{
    public int $id;
    public string $name;
    public string $content;
    public string $compiled;
    public array $schema;
    public string $hash;
    public ?int $cache_ttl;
    public string $created_at;
    public string $updated_at;

    public function __construct(array $data)
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
}
