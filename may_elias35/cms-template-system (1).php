<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManager;
use App\Core\Template\Services\{ThemeService, RenderService, CacheService};
use App\Core\Template\Models\{Theme, Layout, Component};
use App\Core\Template\Exceptions\{TemplateException, RenderException};
use Illuminate\Support\Facades\{View, Cache, Event};

class TemplateManager
{
    private SecurityManager $security;
    private ThemeService $themeService;
    private RenderService $renderer;
    private CacheService $cache;

    public function __construct(
        SecurityManager $security,
        ThemeService $themeService,
        RenderService $renderer,
        CacheService $cache
    ) {
        $this->security = $security;
        $this->themeService = $themeService;
        $this->renderer = $renderer;
        $this->cache = $cache;
    }

    /**
     * Render content with template
     */
    public function render(string $template, array $data, ?string $theme = null): string
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processRendering($template, $data, $theme),
            ['action' => 'render_template', 'template' => $template]
        );
    }

    private function processRendering(string $template, array $data, ?string $theme): string
    {
        // Validate and sanitize data
        $safeData = $this->renderer->sanitizeData($data);
        
        // Get active theme or default
        $activeTheme = $this->themeService->resolveTheme($theme);
        
        // Generate cache key
        $cacheKey = $this->generateCacheKey($template, $safeData, $activeTheme);
        
        // Try to get from cache
        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }

        try {
            // Load and validate template
            $templatePath = $this->themeService->resolveTemplatePath(
                $template, 
                $activeTheme
            );
            
            // Compile template
            $compiled = $this->renderer->compile(
                $templatePath,
                $safeData,
                $activeTheme
            );
            
            // Cache the result
            $this->cache->put($cacheKey, $compiled);
            
            return $compiled;
            
        } catch (\Throwable $e) {
            throw new RenderException(
                "Failed to render template: {$e->getMessage()}",
                previous: $e
            );
        }
    }

    /**
     * Register custom component
     */
    public function registerComponent(string $name, array $config): void
    {
        $this->security->executeCriticalOperation(
            fn() => $this->processComponentRegistration($name, $config),
            ['action' => 'register_component', 'name' => $name]
        );
    }

    private function processComponentRegistration(string $name, array $config): void
    {
        // Validate component configuration
        $this->renderer->validateComponentConfig($config);
        
        // Register with renderer
        $this->renderer->registerComponent($name, $config);
        
        // Clear relevant caches
        $this->cache->clearComponentCache($name);
    }

    /**
     * Install theme with validation
     */
    public function installTheme(string $path): Theme
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->themeService->installTheme($path),
            ['action' => 'install_theme', 'path' => $path]
        );
    }

    /**
     * Get active theme configuration
     */
    public function getActiveTheme(): array
    {
        return $this->cache->remember(
            'active_theme_config',
            fn() => $this->themeService->getActiveThemeConfig()
        );
    }

    /**
     * Generate secure cache key for template
     */
    private function generateCacheKey(string $template, array $data, string $theme): string
    {
        return hash('sha256', serialize([
            'template' => $template,
            'data' => $data,
            'theme' => $theme,
            'version' => $this->themeService->getThemeVersion($theme)
        ]));
    }

    /**
     * Clear template cache
     */
    public function clearCache(string $template = null): void
    {
        $this->security->executeCriticalOperation(
            fn() => $this->cache->clearTemplateCache($template),
            ['action' => 'clear_cache', 'template' => $template]
        );
    }

    /**
     * Validate template syntax
     */
    public function validateTemplate(string $template): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->renderer->validateSyntax($template),
            ['action' => 'validate_template']
        );
    }
}
