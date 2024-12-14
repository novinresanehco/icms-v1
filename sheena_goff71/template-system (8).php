<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Support\Facades\View;

class TemplateManager implements TemplateInterface 
{
    private SecurityManager $security;
    private CacheManager $cache;
    private Factory $viewFactory;
    private array $securityConfig;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        Factory $viewFactory,
        array $securityConfig
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->viewFactory = $viewFactory;
        $this->securityConfig = $securityConfig;
    }

    /**
     * Renders a template with content securely
     */
    public function render(string $template, array $data = []): string 
    {
        // Validate template access
        $this->security->validateTemplateAccess($template);
        
        // Sanitize input data
        $sanitizedData = $this->sanitizeData($data);

        // Get cached version if available
        $cacheKey = $this->getCacheKey($template, $sanitizedData);
        
        return $this->cache->remember($cacheKey, config('cache.ttl'), function() use ($template, $sanitizedData) {
            // Render with validation 
            $rendered = $this->viewFactory->make($template, $sanitizedData)->render();
            
            // Post-render security check
            $this->validateRenderedContent($rendered);
            
            return $rendered;
        });
    }

    /**
     * Compiles a template with security checks
     */
    public function compile(string $template): string 
    {
        // Validate template source
        $this->security->validateTemplateSource($template);

        return DB::transaction(function() use ($template) {
            // Parse and validate template structure
            $parsed = $this->parseTemplate($template);
            
            // Compile with security constraints
            $compiled = $this->compileTemplate($parsed);
            
            // Validate compiled output
            $this->validateCompiledTemplate($compiled);
            
            return $compiled;
        });
    }

    /**
     * Renders content within security constraints
     */
    public function renderContent(Content $content): string 
    {
        // Validate content access
        $this->security->validateContentAccess($content);

        // Get appropriate template
        $template = $this->resolveContentTemplate($content);

        // Render with content data
        return $this->render($template, [
            'content' => $content,
            'security' => $this->securityConfig
        ]);
    }

    /**
     * Renders media gallery with validation
     */
    public function renderMediaGallery(array $media): string 
    {
        // Validate media access 
        foreach ($media as $item) {
            $this->security->validateMediaAccess($item);
        }

        return $this->render('components.media-gallery', [
            'media' => $media,
            'config' => $this->securityConfig 
        ]);
    }

    /**
     * Sanitizes template data for secure rendering
     */
    private function sanitizeData(array $data): array 
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            // Apply escaping/filtering based on context
            $sanitized[$key] = $this->escapeValue($value);
        }
        
        return $sanitized;
    }

    private function validateRenderedContent(string $content): void 
    {
        // Check for script injection
        if ($this->containsScriptTags($content)) {
            throw new SecurityException('Script tags detected in rendered content');
        }

        // Validate output encoding
        if (!$this->isValidEncoding($content)) {
            throw new SecurityException('Invalid content encoding detected');
        }
    }

    private function getCacheKey(string $template, array $data): string 
    {
        return 'template:' . md5($template . serialize($data));
    }
}
