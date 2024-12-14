<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManagerInterface;
use App\Core\Validation\ValidationService;
use Illuminate\Support\Facades\Log;

/**
 * Core template engine with comprehensive security and caching
 */
class TemplateManager implements TemplateManagerInterface 
{
    private SecurityManagerInterface $security;
    private CacheManagerInterface $cache;
    private ValidationService $validator;
    private array $registeredComponents = [];

    public function __construct(
        SecurityManagerInterface $security,
        CacheManagerInterface $cache,
        ValidationService $validator
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
    }

    /**
     * Securely renders a template with content
     *
     * @throws TemplateException
     * @throws SecurityException
     */
    public function render(string $template, array $data = []): string
    {
        try {
            // Validate template and data
            $this->validateTemplate($template);
            $this->validateData($data);

            // Get cached if available
            $cacheKey = $this->getCacheKey($template, $data);
            if ($cached = $this->cache->get($cacheKey)) {
                return $cached;
            }

            // Secure rendering process
            $rendered = $this->renderWithProtection($template, $data);

            // Cache the result
            $this->cache->put($cacheKey, $rendered, $this->getCacheDuration($template));

            return $rendered;

        } catch (\Exception $e) {
            Log::error('Template rendering failed', [
                'template' => $template,
                'error' => $e->getMessage()
            ]);
            throw new TemplateException('Failed to render template: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Registers a UI component for use in templates
     *
     * @throws ComponentException
     */
    public function registerComponent(string $name, callable $component): void 
    {
        if (!$this->validator->validateComponentName($name)) {
            throw new ComponentException('Invalid component name');
        }

        if (isset($this->registeredComponents[$name])) {
            throw new ComponentException('Component already registered');
        }

        $this->registeredComponents[$name] = $component;
    }

    /**
     * Renders a UI component with security checks
     *
     * @throws ComponentException
     * @throws SecurityException  
     */
    public function renderComponent(string $name, array $props = []): string
    {
        if (!isset($this->registeredComponents[$name])) {
            throw new ComponentException('Component not registered');
        }

        // Validate props
        $this->validateComponentProps($name, $props);

        // Render with security context
        return $this->security->executeInContext(
            fn() => ($this->registeredComponents[$name])($props)
        );
    }

    /**
     * Validates template file security
     */
    private function validateTemplate(string $template): void
    {
        if (!$this->validator->validateTemplatePath($template)) {
            throw new SecurityException('Invalid template path');
        }

        if (!$this->security->validateFileAccess($template)) {
            throw new SecurityException('Template access denied');
        }
    }

    /**
     * Validates template data
     */
    private function validateData(array $data): void
    {
        foreach ($data as $key => $value) {
            if (!$this->validator->validateTemplateVar($key, $value)) {
                throw new ValidationException("Invalid template data: $key");
            }
        }
    }

    /**
     * Renders template with security protection
     */
    private function renderWithProtection(string $template, array $data): string
    {
        return $this->security->executeInContext(function() use ($template, $data) {
            // Extract data in isolated scope
            extract($data);
            
            // Start output buffer
            ob_start();
            
            // Include template
            include $template;
            
            // Get and clean buffer
            return ob_get_clean();
        });
    }

    /**
     * Generates secure cache key for template
     */
    private function getCacheKey(string $template, array $data): string
    {
        return hash_hmac(
            'sha256',
            $template . serialize($data),
            config('app.key')
        );
    }

    /**
     * Gets cache duration for template
     */
    private function getCacheDuration(string $template): int
    {
        // Implementation specific cache duration logic
        return config('template.cache_duration', 3600);
    }

    /**
     * Validates component props
     */
    private function validateComponentProps(string $name, array $props): void
    {
        foreach ($props as $key => $value) {
            if (!$this->validator->validateComponentProp($name, $key, $value)) {
                throw new ValidationException("Invalid component prop: $key");
            }
        }
    }
}
