<?php

namespace App\Core\Template;

use Illuminate\Support\Facades\{Cache, View, Storage, Log};
use App\Core\Security\SecurityManager;
use App\Core\Services\{ValidationService, SanitizerService};
use App\Core\Exceptions\{TemplateException, SecurityException};

class TemplateManager implements TemplateInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private SanitizerService $sanitizer;
    
    private const CACHE_TTL = 3600;
    private const TEMPLATE_PATH = 'templates';
    private const MAX_TEMPLATE_SIZE = 1048576; // 1MB

    private array $allowedDirectives = [
        'if', 'else', 'endif', 'foreach', 'endforeach', 
        'include', 'extends', 'yield', 'section', 'show'
    ];

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        SanitizerService $sanitizer
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->sanitizer = $sanitizer;
    }

    public function render(string $template, array $data = []): string
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeRender($template, $data),
            ['action' => 'template.render', 'template' => $template]
        );
    }

    protected function executeRender(string $template, array $data): string
    {
        $this->validateTemplate($template);
        $sanitizedData = $this->sanitizeData($data);

        $cacheKey = $this->getCacheKey($template, $sanitizedData);

        return Cache::remember($cacheKey, self::CACHE_TTL, function() use ($template, $sanitizedData) {
            try {
                return $this->compileAndRender($template, $sanitizedData);
            } catch (\Exception $e) {
                Log::error('Template rendering failed', [
                    'template' => $template,
                    'error' => $e->getMessage()
                ]);
                throw new TemplateException('Failed to render template: ' . $e->getMessage());
            }
        });
    }

    public function compile(string $template): string
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeCompile($template),
            ['action' => 'template.compile', 'template' => $template]
        );
    }

    protected function executeCompile(string $template): string
    {
        $this->validateTemplate($template);

        try {
            $compiled = $this->compileTemplate($template);
            $this->validateCompiled($compiled);
            return $compiled;

        } catch (\Exception $e) {
            throw new TemplateException('Template compilation failed: ' . $e->getMessage());
        }
    }

    public function registerDirective(string $name, callable $handler): void
    {
        $this->security->executeCriticalOperation(
            fn() => $this->executeRegisterDirective($name, $handler),
            ['action' => 'template.register_directive', 'directive' => $name]
        );
    }

    protected function executeRegisterDirective(string $name, callable $handler): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new TemplateException('Invalid directive name');
        }

        if (in_array($name, $this->allowedDirectives)) {
            throw new TemplateException('Cannot override core directives');
        }

        View::directive($name, function(...$args) use ($handler) {
            try {
                return $handler(...$args);
            } catch (\Exception $e) {
                throw new TemplateException("Directive {$name} failed: " . $e->getMessage());
            }
        });
    }

    protected function validateTemplate(string $template): void
    {
        if (!Storage::exists($this->getTemplatePath($template))) {
            throw new TemplateException('Template not found');
        }

        $size = Storage::size($this->getTemplatePath($template));
        if ($size > self::MAX_TEMPLATE_SIZE) {
            throw new TemplateException('Template size exceeds limit');
        }
    }

    protected function validateCompiled(string $compiled): void
    {
        $this->validateSyntax($compiled);
        $this->validateSecurity($compiled);
    }

    protected function validateSyntax(string $compiled): void
    {
        $tokens = token_get_all($compiled);
        $openTags = 0;

        foreach ($tokens as $token) {
            if (is_array($token)) {
                switch ($token[0]) {
                    case T_OPEN_TAG:
                        $openTags++;
                        break;
                    case T_CLOSE_TAG:
                        $openTags--;
                        break;
                }
            }
        }

        if ($openTags !== 0) {
            throw new TemplateException('Invalid template syntax');
        }
    }

    protected function validateSecurity(string $compiled): void
    {
        $dangerousFunctions = [
            'eval', 'exec', 'shell_exec', 'system', 'passthru', 
            'proc_open', 'popen', 'curl_exec', 'file_get_contents'
        ];

        foreach ($dangerousFunctions as $function) {
            if (stripos($compiled, $function) !== false) {
                throw new SecurityException('Dangerous function detected in template');
            }
        }
    }

    protected function sanitizeData(array $data): array
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeData($value);
            } else {
                $sanitized[$key] = $this->sanitizer->sanitize($value);
            }
        }

        return $sanitized;
    }

    protected function compileAndRender(string $template, array $data): string
    {
        $compiled = $this->compileTemplate($template);
        
        return View::make($template, $data)->render();
    }

    protected function compileTemplate(string $template): string
    {
        $content = Storage::get($this->getTemplatePath($template));
        
        // Process custom directives
        $content = $this->processCustomDirectives($content);
        
        // Process includes
        $content = $this->processIncludes($content);
        
        // Process standard directives
        $content = $this->processDirectives($content);
        
        return $content;
    }

    protected function processCustomDirectives(string $content): string
    {
        $pattern = '/@([a-zA-Z_][a-zA-Z0-9_]*)\((.*?)\)/';

        return preg_replace_callback($pattern, function($matches) {
            $directive = $matches[1];
            $arguments = $matches[2];

            if (!in_array($directive, $this->allowedDirectives)) {
                throw new TemplateException("Unsupported directive: {$directive}");
            }

            return "<?php echo \$this->compile{$directive}({$arguments}); ?>";
        }, $content);
    }

    protected function processIncludes(string $content): string
    {
        return preg_replace_callback('/@include\([\'"]([^\'"]+)[\'"]\)/', function($matches) {
            $includePath = $matches[1];
            $this->validateTemplate($includePath);
            return Storage::get($this->getTemplatePath($includePath));
        }, $content);
    }

    protected function processDirectives(string $content): string
    {
        $directives = [
            '/@if\((.*?)\)/' => '<?php if($1): ?>',
            '/@else/' => '<?php else: ?>',
            '/@endif/' => '<?php endif; ?>',
            '/@foreach\((.*?)\)/' => '<?php foreach($1): ?>',
            '/@endforeach/' => '<?php endforeach; ?>'
        ];

        foreach ($directives as $pattern => $replacement) {
            $content = preg_replace($pattern, $replacement, $content);
        }

        return $content;
    }

    protected function getTemplatePath(string $template): string
    {
        return self::TEMPLATE_PATH . '/' . $template . '.blade.php';
    }

    protected function getCacheKey(string $template, array $data): string
    {
        return 'template.' . md5($template . serialize($data));
    }
}
