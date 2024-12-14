<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManagerInterface;
use App\Core\Validation\ValidationService;
use Illuminate\Contracts\View\Factory;

class TemplateEngine implements TemplateEngineInterface 
{
    private SecurityManagerInterface $security;
    private CacheManagerInterface $cache;
    private ValidationService $validator;
    private Factory $viewFactory;
    
    public function __construct(
        SecurityManagerInterface $security,
        CacheManagerInterface $cache, 
        ValidationService $validator,
        Factory $viewFactory
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->viewFactory = $viewFactory;
    }

    /**
     * Renders a template with security checks and caching
     */
    public function render(string $template, array $data = []): string
    {
        // Validate template name and data
        $this->validator->validateTemplate($template);
        $this->validator->validateTemplateData($data);
        
        // Security context check
        $this->security->validateTemplateAccess($template);
        
        // Try cache first
        $cacheKey = $this->getCacheKey($template, $data);
        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }
        
        // Render with security scanning
        $content = $this->renderSecure($template, $data);
        
        // Cache the result
        $this->cache->put($cacheKey, $content, config('template.cache_ttl'));
        
        return $content;
    }

    /**
     * Renders a template with full security scanning
     */
    private function renderSecure(string $template, array $data): string
    {
        // Sanitize all input data
        $data = $this->security->sanitizeData($data);
        
        // Render with error control
        try {
            $content = $this->viewFactory->make($template, $data)->render();
        } catch (\Throwable $e) {
            throw new TemplateRenderException(
                "Failed to render template: {$template}",
                previous: $e
            );
        }
        
        // Scan output for security issues
        $this->security->validateOutput($content);
        
        return $content;
    }

    /**
     * Generates a cache key for a template
     */
    private function getCacheKey(string $template, array $data): string
    {
        return 'template:' . md5($template . serialize($data));
    }
}

interface TemplateEngineInterface
{
    public function render(string $template, array $data = []): string;
}

class TemplateRenderException extends \RuntimeException {}
