<?php

namespace App\Services;

use App\Core\Template\TemplateEngine;
use App\Core\Template\ComponentRegistry;
use App\Core\Exceptions\TemplateException;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\View;
use Illuminate\View\FileViewFinder;

class TemplateService
{
    protected TemplateEngine $engine;
    protected ComponentRegistry $components;
    protected CacheManager $cache;
    protected FileViewFinder $finder;
    
    /**
     * TemplateService constructor.
     */
    public function __construct(
        TemplateEngine $engine,
        ComponentRegistry $components,
        CacheManager $cache,
        FileViewFinder $finder
    ) {
        $this->engine = $engine;
        $this->components = $components;
        $this->cache = $cache;
        $this->finder = $finder;
    }

    /**
     * Render a template with data
     */
    public function render(string $template, array $data = [], array $options = []): string
    {
        try {
            $cacheKey = $this->getCacheKey($template, $data);

            return $this->cache->remember($cacheKey, function() use ($template, $data, $options) {
                $compiledTemplate = $this->engine->compile($template, $options);
                return $this->engine->render($compiledTemplate, $data);
            });
        } catch (\Exception $e) {
            throw new TemplateException("Template rendering failed: {$e->getMessage()}");
        }
    }

    /**
     * Register a new component
     */
    public function registerComponent(string $name, string $view, array $props = []): void
    {
        try {
            $this->components->register($name, $view, $props);
        } catch (\Exception $e) {
            throw new TemplateException("Component registration failed: {$e->getMessage()}");
        }
    }

    /**
     * Add a new template path
     */
    public function addTemplatePath(string $path): void
    {
        if (!is_dir($path)) {
            throw new TemplateException("Invalid template path: {$path}");
        }

        $this->finder->addLocation($path);
    }

    /**
     * Get layout variables
     */
    public function getLayoutVariables(string $layout): array
    {
        $cacheKey = "layout_vars:{$layout}";

        return $this->cache->remember($cacheKey, function() use ($layout) {
            return $this->engine->analyzeLayout($layout);
        });
    }

    /**
     * Compile a template
     */
    protected function compileTemplate(string $template): string
    {
        return $this->engine->compile($template, [
            'cache' => true,
            'strict_variables' => true,
            'autoescape' => true
        ]);
    }

    /**
     * Generate cache key
     */
    protected function getCacheKey(string $template, array $data): string
    {
        return 'template:' . md5($template . serialize($data));
    }
}
