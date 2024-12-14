<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\{DB, View, File};
use App\Core\Exceptions\{TemplateException, SecurityException};

class TemplateManager
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ThemeManager $themes;
    private ComponentRegistry $components;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ThemeManager $themes,
        ComponentRegistry $components
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->themes = $themes;
        $this->components = $components;
    }

    /**
     * Secure template rendering with caching
     */
    public function render(string $template, array $data = []): string
    {
        return $this->security->executeCriticalOperation(function() use ($template, $data) {
            $cacheKey = $this->getCacheKey($template, $data);
            
            return $this->cache->remember($cacheKey, 60, function() use ($template, $data) {
                // Verify template access
                $this->verifyTemplateAccess($template);
                
                // Sanitize and validate data
                $safeData = $this->sanitizeData($data);
                
                // Load and compile template
                $compiled = $this->compileTemplate($template, $safeData);
                
                // Render with security context
                return $this->renderSecure($compiled, $safeData);
            });
        });
    }

    /**
     * Theme management with security checks
     */
    public function activateTheme(string $theme): ThemeResult
    {
        return $this->security->executeCriticalOperation(function() use ($theme) {
            DB::beginTransaction();
            try {
                // Verify theme integrity
                $this->themes->verifyIntegrity($theme);
                
                // Validate theme security
                $this->validateThemeSecurity($theme);
                
                // Activate theme
                $result = $this->themes->activate($theme);
                
                // Clear template cache
                $this->cache->tags(['templates'])->flush();
                
                DB::commit();
                return new ThemeResult($result);
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw new TemplateException('Theme activation failed: ' . $e->getMessage(), 0, $e);
            }
        });
    }

    /**
     * Component management with security
     */
    public function registerComponent(string $name, array $config): ComponentResult
    {
        return $this->security->executeCriticalOperation(function() use ($name, $config) {
            // Validate component
            $this->validateComponent($config);
            
            // Register with security context
            return new ComponentResult(
                $this->components->register($name, $config)
            );
        });
    }

    /**
     * Layout management with caching
     */
    public function compileLayout(string $layout, array $sections): string
    {
        return $this->security->executeCriticalOperation(function() use ($layout, $sections) {
            $cacheKey = "layout.$layout." . md5(serialize($sections));
            
            return $this->cache->remember($cacheKey, 60, function() use ($layout, $sections) {
                // Verify layout access
                $this->verifyLayoutAccess($layout);
                
                // Process sections
                $processedSections = $this->processSections($sections);
                
                // Compile layout
                return $this->compileLayoutTemplate($layout, $processedSections);
            });
        });
    }

    private function verifyTemplateAccess(string $template): void
    {
        if (!$this->security->hasPermission('template.render')) {
            throw new SecurityException('Access denied for template rendering');
        }

        if (!$this->themes->templateExists($template)) {
            throw new TemplateException('Template not found: ' . $template);
        }
    }

    private function sanitizeData(array $data): array
    {
        return array_map(function($value) {
            if (is_string($value)) {
                return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            return $value;
        }, $data);
    }

    private function compileTemplate(string $template, array $data): string
    {
        try {
            return View::make($template, $data)->render();
        } catch (\Exception $e) {
            throw new TemplateException('Template compilation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function renderSecure(string $compiled, array $data): string
    {
        try {
            // Execute in isolated environment
            return $this->executeInSandbox(function() use ($compiled, $data) {
                extract($data);
                ob_start();
                eval('?>' . $compiled);
                return ob_get_clean();
            });
        } catch (\Exception $e) {
            throw new TemplateException('Secure rendering failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function validateThemeSecurity(string $theme): void
    {
        // Verify theme files
        $this->themes->scanThemeFiles($theme, function($file) {
            if (!$this->isSecureFile($file)) {
                throw new SecurityException('Insecure file detected in theme: ' . $file);
            }
        });

        // Validate theme configuration
        if (!$this->themes->validateConfiguration($theme)) {
            throw new TemplateException('Invalid theme configuration');
        }
    }

    private function validateComponent(array $config): void
    {
        $rules = [
            'name' => 'required|string|max:255',
            'template' => 'required|string',
            'props' => 'array',
            'security' => 'array'
        ];

        $validator = validator($config, $rules);
        
        if ($validator->fails()) {
            throw new TemplateException('Invalid component configuration: ' . json_encode($validator->errors()));
        }
    }

    private function executeInSandbox(callable $code)
    {
        // Set up secure environment
        $previousErrorReporting = error_reporting(E_ALL);
        $previousDisplayErrors = ini_get('display_errors');
        ini_set('display_errors', '0');

        try {
            return $code();
        } finally {
            // Restore environment
            error_reporting($previousErrorReporting);
            ini_set('display_errors', $previousDisplayErrors);
        }
    }

    private function getCacheKey(string $template, array $data): string
    {
        return 'template.' . md5($template . serialize($data));
    }

    private function isSecureFile(string $file): bool
    {
        // Check file extension
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $allowedExtensions = ['php', 'blade.php', 'css', 'js', 'jpg', 'png', 'gif', 'svg'];
        
        if (!in_array($extension, $allowedExtensions)) {
            return false;
        }

        // Scan file content for security issues
        $content = File::get($file);
        return $this->scanFileContent($content);
    }

    private function scanFileContent(string $content): bool
    {
        $dangerousPatterns = [
            '/eval\s*\(/',
            '/shell_exec\s*\(/',
            '/exec\s*\(/',
            '/system\s*\(/',
            '/passthru\s*\(/',
            '/base64_decode\s*\(/',
            '/<\?php.*?\?>/'
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return false;
            }
        }

        return true;
    }
}
