<?php

namespace App\Core\Template;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\View\Factory as ViewFactory;
use App\Core\Security\SecurityManagerInterface;
use App\Core\Validation\ValidationService;

class TemplateEngine implements TemplateEngineInterface 
{
    private ViewFactory $view;
    private Cache $cache;
    private SecurityManagerInterface $security;
    private ValidationService $validator;
    private array $config;

    public function __construct(
        ViewFactory $view,
        Cache $cache,
        SecurityManagerInterface $security,
        ValidationService $validator,
        array $config
    ) {
        $this->view = $view;
        $this->cache = $cache;
        $this->security = $security;
        $this->validator = $validator;
        $this->config = $config;
    }

    /**
     * Renders a template with comprehensive security checks and caching
     *
     * @param string $template Template identifier
     * @param array $data Template data
     * @throws TemplateException If any validation or security check fails
     * @return string Rendered content
     */
    public function render(string $template, array $data = []): string
    {
        // Validate template and data
        $this->validateTemplate($template);
        $this->validateData($data);

        // Generate cache key
        $cacheKey = $this->generateCacheKey($template, $data);

        try {
            // Try to get from cache first
            if ($cached = $this->getFromCache($cacheKey)) {
                return $cached;
            }

            // Security context check
            $this->security->validateContext(['template' => $template, 'data' => $data]);

            // Render template
            $rendered = $this->renderTemplate($template, $this->prepareData($data));

            // Validate output before caching
            $this->validateOutput($rendered);

            // Cache the result
            $this->cache->put($cacheKey, $rendered, $this->config['cache_ttl']);

            return $rendered;

        } catch (\Exception $e) {
            throw new TemplateException(
                "Failed to render template: {$template}",
                previous: $e
            );
        }
    }

    /**
     * Compiles a template with security checks
     */
    public function compile(string $template): string
    {
        $this->validateTemplate($template);

        try {
            return $this->view->make($template)->render();
        } catch (\Exception $e) {
            throw new TemplateException(
                "Failed to compile template: {$template}",
                previous: $e
            );
        }
    }

    /**
     * Prepares data for template rendering with security measures
     */
    private function prepareData(array $data): array
    {
        return array_map(function ($value) {
            if (is_string($value)) {
                return $this->security->sanitizeOutput($value);
            }
            return $value;
        }, $data);
    }

    /**
     * Validates template identifier
     */
    private function validateTemplate(string $template): void
    {
        if (!$this->validator->validateTemplate($template)) {
            throw new TemplateException("Invalid template identifier: {$template}");
        }
    }

    /**
     * Validates template data
     */
    private function validateData(array $data): void
    {
        if (!$this->validator->validateTemplateData($data)) {
            throw new TemplateException("Invalid template data provided");
        }
    }

    /**
     * Validates rendered output
     */
    private function validateOutput(string $output): void
    {
        if (!$this->validator->validateRenderedContent($output)) {
            throw new TemplateException("Generated content failed validation");
        }
    }

    /**
     * Generates secure cache key for template
     */
    private function generateCacheKey(string $template, array $data): string
    {
        return hash('sha256', $template . serialize($data));
    }

    /**
     * Retrieves cached template if available
     */
    private function getFromCache(string $key): ?string
    {
        if ($this->config['caching_enabled']) {
            return $this->cache->get($key);
        }
        return null;
    }

    /**
     * Renders template with error handling
     */
    private function renderTemplate(string $template, array $data): string
    {
        try {
            return $this->view->make($template, $data)->render();
        } catch (\Exception $e) {
            // Log the error with context but without sensitive data
            logger()->error('Template rendering failed', [
                'template' => $template,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}
