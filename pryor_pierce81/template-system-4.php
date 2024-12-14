<?php

namespace App\Core\Template;

use Illuminate\Support\Facades\{DB, Cache, View};
use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Content\ContentManager;

class TemplateManager implements TemplateInterface
{
    protected SecurityManager $security;
    protected CacheManager $cache;
    protected ContentManager $content;
    protected TemplateRepository $repository;
    protected TemplateCompiler $compiler;
    protected array $config;
    
    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ContentManager $content,
        TemplateRepository $repository,
        TemplateCompiler $compiler,
        array $config
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->content = $content;
        $this->repository = $repository;
        $this->compiler = $compiler;
        $this->config = $config;
    }

    public function render(string $template, array $data = []): string
    {
        return $this->security->executeCriticalOperation(function() use ($template, $data) {
            $cacheKey = $this->getCacheKey($template, $data);
            
            return $this->cache->tags(['templates'])->remember($cacheKey, function() use ($template, $data) {
                $template = $this->loadTemplate($template);
                $compiledTemplate = $this->compileTemplate($template, $data);
                
                return $this->renderTemplate($compiledTemplate, $data);
            });
        });
    }

    public function compile(string $template): string
    {
        return $this->security->executeCriticalOperation(function() use ($template) {
            $this->validateTemplate($template);
            return $this->compiler->compile($template);
        });
    }

    public function registerFunction(string $name, callable $function): void
    {
        $this->security->executeCriticalOperation(function() use ($name, $function) {
            if ($this->isValidFunctionName($name)) {
                $this->compiler->registerFunction($name, $function);
            }
        });
    }

    public function extendWith(string $name, callable $extension): void
    {
        $this->security->executeCriticalOperation(function() use ($name, $extension) {
            if ($this->isValidExtensionName($name)) {
                $this->compiler->extend($name, $extension);
            }
        });
    }

    protected function loadTemplate(string $name): string
    {
        $template = $this->repository->findByName($name);
        
        if (!$template) {
            throw new TemplateNotFoundException("Template not found: {$name}");
        }

        return $template->content;
    }

    protected function compileTemplate(string $template, array $data): string
    {
        $compiled = $this->compiler->compile($template);
        
        foreach ($data as $key => $value) {
            $compiled = $this->bindData($compiled, $key, $value);
        }

        return $compiled;
    }

    protected function renderTemplate(string $template, array $data): string
    {
        return View::make('template::container', [
            'template' => $template,
            'data' => $this->sanitizeData($data)
        ])->render();
    }

    protected function validateTemplate(string $template): void
    {
        if (!$this->compiler->validate($template)) {
            throw new InvalidTemplateException('Template validation failed');
        }

        if (!$this->validateSecurity($template)) {
            throw new TemplateSecurity('Template contains security vulnerabilities');
        }
    }

    protected function validateSecurity(string $template): bool
    {
        foreach ($this->config['security_patterns'] as $pattern) {
            if (preg_match($pattern, $template)) {
                return false;
            }
        }

        return true;
    }

    protected function sanitizeData(array $data): array
    {
        return array_map(function($value) {
            if (is_string($value)) {
                return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            if (is_array($value)) {
                return $this->sanitizeData($value);
            }
            return $value;
        }, $data);
    }

    protected function bindData(string $template, string $key, $value): string
    {
        $pattern = $this->compiler->getBindingPattern($key);
        return preg_replace($pattern, $this->formatValue($value), $template);
    }

    protected function formatValue($value): string
    {
        if (is_callable($value)) {
            throw new TemplateSecurityException('Callable values are not allowed in templates');
        }

        if (is_array($value) || is_object($value)) {
            return htmlspecialchars(json_encode($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    protected function getCacheKey(string $template, array $data): string
    {
        return 'template.' . md5($template . serialize($data));
    }

    protected function isValidFunctionName(string $name): bool
    {
        return preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/', $name)
            && !in_array($name, $this->config['reserved_words']);
    }

    protected function isValidExtensionName(string $name): bool
    {
        return preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/', $name)
            && !in_array($name, $this->config['reserved_extensions']);
    }
}
