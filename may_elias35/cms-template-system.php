<?php

namespace App\Core\Template;

use App\Core\Security\CoreSecurityManager;
use App\Core\Cache\CacheManager;
use Illuminate\Contracts\Filesystem\Filesystem;

class TemplateManager implements TemplateManagerInterface
{
    private CoreSecurityManager $security;
    private TemplateRepository $repository;
    private ComponentRegistry $components;
    private ThemeManager $themeManager;
    private CacheManager $cache;
    private Filesystem $storage;
    private AuditLogger $auditLogger;

    public function __construct(
        CoreSecurityManager $security,
        TemplateRepository $repository,
        ComponentRegistry $components,
        ThemeManager $themeManager,
        CacheManager $cache,
        Filesystem $storage,
        AuditLogger $auditLogger
    ) {
        $this->security = $security;
        $this->repository = $repository;
        $this->components = $components;
        $this->themeManager = $themeManager;
        $this->cache = $cache;
        $this->storage = $storage;
        $this->auditLogger = $auditLogger;
    }

    public function renderTemplate(string $template, array $data, ?string $theme = null): string
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeTemplateRender($template, $data, $theme),
            new SecurityContext('template:render', [
                'template' => $template,
                'theme' => $theme
            ])
        );
    }

    private function executeTemplateRender(string $template, array $data, ?string $theme): string
    {
        // Get active theme or fallback to default
        $activeTheme = $theme ? $this->themeManager->getTheme($theme) : 
                               $this->themeManager->getDefaultTheme();

        // Validate template exists
        if (!$this->repository->exists($template, $activeTheme)) {
            throw new TemplateNotFoundException("Template not found: {$template}");
        }

        // Cache key based on template, data, and theme
        $cacheKey = $this->generateCacheKey($template, $data, $activeTheme);

        return $this->cache->remember($cacheKey, config('cache.template_ttl'), function() 
            use ($template, $data, $activeTheme) {
                // Load template content
                $content = $this->repository->load($template, $activeTheme);
                
                // Parse and validate template syntax
                $parsed = $this->parseTemplate($content);
                
                // Process components and includes
                $processed = $this->processComponents($parsed, $data);
                
                // Apply theme layouts and styling
                $themed = $this->applyTheme($processed, $activeTheme);
                
                // Final security sanitization
                return $this->sanitizeOutput($themed);
        });
    }

    private function parseTemplate(string $content): Template
    {
        try {
            // Parse template syntax
            $ast = $this->parser->parse($content);
            
            // Validate template structure
            $this->validator->validateTemplate($ast);
            
            return new Template($ast);
        } catch (ParseException $e) {
            $this->auditLogger->logError('Template parsing failed', [
                'error' => $e->getMessage(),
                'template' => $content
            ]);
            throw $e;
        }
    }

    private function processComponents(Template $template, array $data): string
    {
        return $template->render(function($component, $props) {
            // Validate component exists and is allowed
            if (!$this->components->has($component)) {
                throw new ComponentNotFoundException("Component not found: {$component}");
            }

            // Validate component props
            $validatedProps = $this->validator->validateComponentProps(
                $component, 
                $props
            );

            // Render component with security context
            return $this->components->render($component, $validatedProps);
        }, $data);
    }

    public function registerComponent(string $name, array $config): void
    {
        $this->security->validateSecureOperation(
            fn() => $this->executeComponentRegistration($name, $config),
            new SecurityContext('component:register', [
                'name' => $name,
                'config' => $config
            ])
        );
    }

    private function executeComponentRegistration(string $name, array $config): void
    {
        // Validate component configuration
        $validatedConfig = $this->validator->validateComponentConfig($config);

        // Register component
        $this->components->register($name, $validatedConfig);

        // Clear component cache
        $this->cache->invalidateComponentCache($name);

        // Log registration
        $this->auditLogger->logInfo('Component registered', [
            'name' => $name,
            'type' => $config['type'] ?? 'custom'
        ]);
    }

    public function setTheme(string $theme): void
    {
        $this->security->validateSecureOperation(
            fn() => $this->executeThemeChange($theme),
            new SecurityContext('theme:change', ['theme' => $theme])
        );
    }

    private function executeThemeChange(string $theme): void
    {
        // Validate theme exists and is compatible
        if (!$this->themeManager->isValid($theme)) {
            throw new ThemeCompatibilityException("Invalid theme: {$theme}");
        }

        // Activate theme
        $this->themeManager->activate($theme);

        // Clear theme-related caches
        $this->cache->invalidateThemeCaches();

        // Log theme change
        $this->auditLogger->logInfo('Theme changed', ['theme' => $theme]);
    }

    private function sanitizeOutput(string $output): string
    {
        return $this->security->sanitizeHtml($output, [
            'allowed_tags' => config('template.allowed_tags'),
            'allowed_attributes' => config('template.allowed_attributes')
        ]);
    }

    private function generateCacheKey(string $template, array $data, Theme $theme): string
    {
        return 'template:' . md5(serialize([
            'name' => $template,
            'data' => $data,
            'theme' => $theme->getId(),
            'version' => $theme->getVersion()
        ]));
    }
}
