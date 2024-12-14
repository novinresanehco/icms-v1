<?php

namespace App\Core\Template;

use Illuminate\Support\Facades\{Cache, View, Log};
use App\Core\Security\{SecurityManager, XSSProtection};
use App\Core\Cache\CacheManager;
use App\Core\Exceptions\{TemplateException, SecurityException};

class TemplateEngine implements TemplateEngineInterface
{
    private SecurityManager $security;
    private XSSProtection $xssProtection;
    private CacheManager $cache;
    private array $config;

    public function __construct(
        SecurityManager $security,
        XSSProtection $xssProtection,
        CacheManager $cache,
        array $config
    ) {
        $this->security = $security;
        $this->xssProtection = $xssProtection;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function render(string $template, array $data = []): string
    {
        try {
            // Validate template and data
            $this->validateTemplate($template);
            $this->validateData($data);

            // Get cached version if available
            $cacheKey = $this->generateCacheKey($template, $data);
            if ($cached = $this->getCachedTemplate($cacheKey)) {
                return $cached;
            }

            // Compile and render template
            $compiled = $this->compileTemplate($template, $data);
            $rendered = $this->renderTemplate($compiled, $data);

            // Cache the result
            $this->cacheTemplate($cacheKey, $rendered);

            return $rendered;

        } catch (\Exception $e) {
            $this->handleRenderFailure($e, $template);
            throw new TemplateException('Template rendering failed', 0, $e);
        }
    }

    public function registerComponent(string $name, array $component): void
    {
        try {
            // Validate component
            $this->validateComponent($component);

            // Register with security checks
            $this->security->executeCriticalOperation(
                fn() => $this->executeComponentRegistration($name, $component),
                ['action' => 'register_component', 'name' => $name]
            );

        } catch (\Exception $e) {
            throw new TemplateException('Component registration failed', 0, $e);
        }
    }

    private function validateTemplate(string $template): void
    {
        if (!$this->security->validateTemplatePath($template)) {
            throw new SecurityException('Invalid template path');
        }

        if (!file_exists($this->resolveTemplatePath($template))) {
            throw new TemplateException('Template file not found');
        }
    }

    private function validateData(array $data): void
    {
        foreach ($data as $key => $value) {
            if (!$this->security->validateTemplateData($key, $value)) {
                throw new SecurityException('Invalid template data');
            }
        }
    }

    private function compileTemplate(string $template, array $data): string
    {
        $compiler = new TemplateCompiler($this->security, $this->config);
        return $compiler->compile($template);
    }

    private function renderTemplate(string $compiled, array $data): string
    {
        // Create isolated rendering environment
        $renderer = new SecureTemplateRenderer($this->security, $this->xssProtection);
        
        // Render with security context
        $rendered = $renderer->render($compiled, $this->sanitizeData($data));

        // Validate output
        $this->validateOutput($rendered);

        return $rendered;
    }

    private function sanitizeData(array $data): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            $sanitized[$key] = $this->xssProtection->sanitize($value);
        }
        return $sanitized;
    }

    private function validateOutput(string $output): void
    {
        if (!$this->security->validateTemplateOutput($output)) {
            throw new SecurityException('Template output validation failed');
        }
    }

    private function generateCacheKey(string $template, array $data): string
    {
        return hash('sha256', $template . serialize($data));
    }

    private function getCachedTemplate(string $key): ?string
    {
        if ($this->config['caching_enabled']) {
            return $this->cache->get("template:$key");
        }
        return null;
    }

    private function cacheTemplate(string $key, string $content): void
    {
        if ($this->config['caching_enabled']) {
            $this->cache->put(
                "template:$key",
                $content,
                $this->config['cache_duration']
            );
        }
    }

    private function validateComponent(array $component): void
    {
        $required = ['template', 'scripts', 'styles'];
        foreach ($required as $field) {
            if (!isset($component[$field])) {
                throw new TemplateException("Missing required component field: $field");
            }
        }

        // Validate component security
        if (!$this->security->validateComponent($component)) {
            throw new SecurityException('Component security validation failed');
        }
    }

    private function executeComponentRegistration(string $name, array $component): void
    {
        // Register component
        ComponentRegistry::register($name, $component);

        // Clear component cache
        $this->cache->tags(['components'])->flush();

        // Log registration
        Log::info("Component registered: $name", [
            'name' => $name,
            'timestamp' => now()
        ]);
    }

    private function handleRenderFailure(\Exception $e, string $template): void
    {
        Log::error('Template render failed', [
            'template' => $template,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        // Clear potentially corrupted cache
        $this->cache->tags(['templates'])->flush();
    }
}
