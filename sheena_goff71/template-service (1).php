<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManagerInterface;
use App\Core\Validation\ValidationServiceInterface;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Facades\Log;

class TemplateService implements TemplateServiceInterface
{
    private SecurityManagerInterface $security;
    private CacheManagerInterface $cache;
    private ValidationServiceInterface $validator;
    private ViewFactory $view;

    public function __construct(
        SecurityManagerInterface $security,
        CacheManagerInterface $cache,
        ValidationServiceInterface $validator,
        ViewFactory $view
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->view = $view;
    }

    /**
     * Renders a template with comprehensive security checks and caching
     * 
     * @param string $template Template identifier
     * @param array $data Data to be rendered
     * @param array $options Rendering options
     * @return string Rendered template content
     * @throws TemplateException If rendering fails
     */
    public function render(string $template, array $data = [], array $options = []): string
    {
        try {
            // Validate template and data before processing
            $this->validateRenderRequest($template, $data, $options);

            // Check cache first
            $cacheKey = $this->generateCacheKey($template, $data);
            if ($cached = $this->cache->get($cacheKey)) {
                Log::debug('Template served from cache', ['template' => $template]);
                return $cached;
            }

            // Process and validate data
            $processedData = $this->processTemplateData($data);
            
            // Render with security context
            $rendered = $this->security->executeInContext(function() use ($template, $processedData) {
                return $this->view->make($template, $processedData)->render();
            });

            // Validate output before caching
            $this->validateOutput($rendered);

            // Cache the result
            $this->cache->put($cacheKey, $rendered, $options['cache_ttl'] ?? 3600);

            return $rendered;

        } catch (\Throwable $e) {
            Log::error('Template rendering failed', [
                'template' => $template,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new TemplateException(
                'Failed to render template: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    private function validateRenderRequest(string $template, array $data, array $options): void
    {
        // Validate template path
        if (!$this->validator->validateTemplatePath($template)) {
            throw new TemplateException('Invalid template path');
        }

        // Validate input data
        if (!$this->validator->validateTemplateData($data)) {
            throw new TemplateException('Invalid template data');
        }

        // Validate rendering options
        if (!$this->validator->validateRenderOptions($options)) {
            throw new TemplateException('Invalid render options');
        }
    }

    private function processTemplateData(array $data): array
    {
        return array_map(function ($value) {
            if (is_string($value)) {
                return $this->security->sanitizeOutput($value);
            }
            return $value;
        }, $data);
    }

    private function validateOutput(string $output): void
    {
        if (!$this->validator->validateTemplateOutput($output)) {
            throw new TemplateException('Template output validation failed');
        }
    }

    private function generateCacheKey(string $template, array $data): string
    {
        return 'template:' . md5($template . serialize($data));
    }
}
