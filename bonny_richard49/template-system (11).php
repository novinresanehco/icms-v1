<?php

namespace App\Core\Template;

use Illuminate\Support\Facades\{Cache, View, Storage};

class TemplateManager
{
    private SecurityManager $security;
    private TemplateRepository $repository;
    private TemplateValidator $validator;
    private TemplateCache $cache;
    private Renderer $renderer;

    public function __construct(
        SecurityManager $security,
        TemplateRepository $repository,
        TemplateValidator $validator,
        TemplateCache $cache,
        Renderer $renderer
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->renderer = $renderer;
    }

    public function render(string $template, array $data = []): string
    {
        return $this->security->protectedExecute(function() use ($template, $data) {
            $this->validator->validateTemplate($template);
            $this->validator->validateData($data);
            
            $cacheKey = $this->cache->generateKey($template, $data);
            
            return $this->cache->remember($cacheKey, function() use ($template, $data) {
                $compiled = $this->repository->getCompiled($template);
                return $this->renderer->render($compiled, $data);
            });
        });
    }

    public function store(string $name, string $content): Template
    {
        return $this->security->protectedExecute(function() use ($name, $content) {
            $this->validator->validateName($name);
            $this->validator->validateContent($content);
            
            $template = $this->repository->create([
                'name' => $name,
                'content' => $content
            ]);
            
            $this->cache->invalidate($template->name);
            return $template;
        });
    }

    public function update(string $name, string $content): Template
    {
        return $this->security->protectedExecute(function() use ($name, $content) {
            $this->validator->validateContent($content);
            
            $template = $this->repository->update($name, [
                'content' => $content
            ]);
            
            $this->cache->invalidate($template->name);
            return $template;
        });
    }

    public function delete(string $name): void
    {
        $this->security->protectedExecute(function() use ($name) {
            $this->repository->delete($name);
            $this->cache->invalidate($name);
        });
    }
}

class TemplateValidator
{
    private array $allowedTags = [
        'p', 'div', 'span', 'strong', 'em', 'ul', 'ol', 'li',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'img', 'a'
    ];

    public function validateTemplate(string $template): void
    {
        if (empty($template)) {
            throw new TemplateValidationException('Template cannot be empty');
        }
    }

    public function validateData(array $data): void
    {
        array_walk_recursive($data, function($value) {
            if (is_string($value)) {
                $this->validateString($value);
            }
        });
    }

    public function validateName(string $name): void
    {
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $name)) {
            throw new TemplateValidationException('Invalid template name');
        }
    }

    public function validateContent(string $content): void
    {
        if (empty($content)) {
            throw new TemplateValidationException('Content cannot be empty');
        }

        $this->validateTags($content);
        $this->validateSecurity($content);
    }

    private function validateString(string $value): void
    {
        if (strlen($value) > 10000) {
            throw new TemplateValidationException('String value too long');
        }
    }

    private function validateTags(string $content): void
    {
        $doc = new \DOMDocument();
        $doc->loadHTML($content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        
        $tags = $doc->getElementsByTagName('*');
        foreach ($tags as $tag) {
            if (!in_array($tag->tagName, $this->allowedTags)) {
                throw new TemplateValidationException("Tag not allowed: {$tag->tagName}");
            }
        }
    }

    private function validateSecurity(string $content): void
    {
        if (preg_match('/<script|javascript:|onclick=|onerror=|onload=/i', $content)) {
            throw new TemplateValidationException('Potential security risk detected');
        }
    }
}

class TemplateCache
{
    private CacheManager $cache;

    public function __construct(CacheManager $cache)
    {
        $this->cache = $cache;
    }

    public function remember(string $key, callable $callback): string
    {
        return $this->cache->remember($key, $callback);
    }

    public function invalidate(string $template): void
    {
        $this->cache->tags(['templates', "template.$template"])->flush();
    }

    public function generateKey(string $template, array $data): string
    {
        return 'template.' . md5($template . serialize($data));
    }
}

class Renderer
{
    private SecurityManager $security;

    public function render(Template $template, array $data): string
    {
        $content = $template->content;
        $content = $this->interpolateVariables($content, $data);
        $content = $this->security->sanitizeOutput($content);
        return $content;
    }

    private function interpolateVariables(string $content, array $data): string
    {
        return preg_replace_callback(
            '/\{\{\s*([^}]+)\s*\}\}/',
            function($matches) use ($data) {
                $key = trim($matches[1]);
                return $this->security->escapeHtml(
                    $this->resolvePath($data, $key)
                );
            },
            $content
        );
    }

    private function resolvePath(array $data, string $path): string
    {
        $segments = explode('.', $path);
        $current = $data;
        
        foreach ($segments as $segment) {
            if (!isset($current[$segment])) {
                return '';
            }
            $current = $current[$segment];
        }
        
        return (string)$current;
    }
}

class Template
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $content
    ) {}
}

class TemplateRepository
{
    private Compiler $compiler;

    public function getCompiled(string $name): Template
    {
        $template = $this->find($name);
        if (!$template) {
            throw new TemplateNotFoundException($name);
        }
        
        $template->content = $this->compiler->compile($template->content);
        return $template;
    }

    public function find(string $name): ?Template
    {
        $data = DB::table('templates')->where('name', $name)->first();
        return $data ? new Template(
            $data->id,
            $data->name,
            $data->content
        ) : null;
    }

    public function create(array $data): Template
    {
        $id = DB::table('templates')->insertGetId($data);
        return new Template($id, $data['name'], $data['content']);
    }

    public function update(string $name, array $data): Template
    {
        DB::table('templates')
            ->where('name', $name)
            ->update($data);
            
        return $this->find($name);
    }

    public function delete(string $name): void
    {
        DB::table('templates')->where('name', $name)->delete();
    }
}

class TemplateValidationException extends \Exception {}
class TemplateNotFoundException extends \Exception {}
