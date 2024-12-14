<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManagerInterface;
use App\Core\Validation\ValidationService;
use Illuminate\Support\Facades\View;

class TemplateManager implements TemplateManagerInterface 
{
    private SecurityManagerInterface $security;
    private CacheManagerInterface $cache;
    private ValidationService $validator;

    public function __construct(
        SecurityManagerInterface $security,
        CacheManagerInterface $cache,
        ValidationService $validator
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
    }

    public function render(string $template, array $data = []): string 
    {
        // Validate template access
        $this->security->validateAccess('template.render', $template);

        // Validate data
        $validatedData = $this->validator->validateTemplateData($data);

        // Check cache
        $cacheKey = $this->getCacheKey($template, $validatedData);
        return $this->cache->remember($cacheKey, 3600, function() use ($template, $validatedData) {
            // Secure template rendering
            return $this->secureParse($template, $validatedData);
        });
    }

    protected function secureParse(string $template, array $data): string
    {
        try {
            // Escape all data values
            $escapedData = $this->escapeData($data);

            // Render with security context
            return View::make($template, $escapedData)
                ->render();

        } catch (\Throwable $e) {
            throw new TemplateException(
                "Failed to render template: {$template}",
                previous: $e
            );
        }
    }

    protected function escapeData(array $data): array 
    {
        return array_map(function($value) {
            if (is_string($value)) {
                return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            }
            if (is_array($value)) {
                return $this->escapeData($value);
            }
            return $value;
        }, $data);
    }

    protected function getCacheKey(string $template, array $data): string
    {
        return 'template:' . md5($template . serialize($data));
    }

    public function registerComponent(string $name, ComponentInterface $component): void
    {
        // Validate component
        $this->validator->validateComponent($component);

        // Register with security check
        $this->security->validateAccess('template.register_component', $name);
        
        View::share($name, $component);
    }

    public function clear(string $template = null): void 
    {
        $this->security->validateAccess('template.clear');
        
        if ($template) {
            $this->cache->forget($this->getCacheKey($template, []));
        } else {
            $this->cache->tags(['templates'])->flush();
        }
    }
}

interface TemplateManagerInterface
{
    public function render(string $template, array $data = []): string;
    public function registerComponent(string $name, ComponentInterface $component): void;
    public function clear(string $template = null): void;
}

interface ComponentInterface
{
    public function render(): string;
    public function validate(): bool;
    public function getSecurityContext(): array;
}
