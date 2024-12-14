<?php

namespace App\Core\Template;

use App\Core\Security\SecurityManager;
use App\Core\Services\{CacheManager, ValidationService};
use App\Core\Template\Exceptions\{TemplateException, RenderException};

class TemplateSystem
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ValidationService $validator;
    private array $registeredComponents = [];
    private array $activeTheme;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        ValidationService $validator
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->loadActiveTheme();
    }

    public function render(string $template, array $data = [], array $options = []): string
    {
        return $this->security->executeCriticalOperation(
            new RenderTemplateOperation($template, $data),
            function() use ($template, $data, $options) {
                // Validate template exists
                if (!$this->templateExists($template)) {
                    throw new TemplateException("Template not found: {$template}");
                }

                // Load template with caching
                $templateContent = $this->loadTemplate($template);

                // Process template inheritance
                $templateContent = $this->processInheritance($templateContent);

                // Compile template
                $compiled = $this->compileTemplate($templateContent);

                // Validate compiled code
                $this->validateCompiledTemplate($compiled);

                // Render with data
                return $this->renderCompiled($compiled, $this->sanitizeData($data), $options);
            }
        );
    }

    public function registerComponent(string $name, callable $callback): void
    {
        $this->registeredComponents[$name] = $callback;
    }

    public function setTheme(string $theme): void
    {
        if (!$this->themeExists($theme)) {
            throw new TemplateException("Theme not found: {$theme}");
        }

        $this->activeTheme = $this->loadTheme($theme);
        $this->cache->put('active_theme', $this->activeTheme, now()->addDay());
    }

    private function loadActiveTheme(): void
    {
        $this->activeTheme = $this->cache->remember(
            'active_theme',
            now()->addDay(),
            fn() => $this->loadTheme(config('cms.default_theme'))
        );
    }

    private function loadTheme(string $theme): array
    {
        $themePath = resource_path("themes/{$theme}");
        
        if (!file_exists($themePath)) {
            throw new TemplateException("Theme directory not found: {$theme}");
        }

        $config = require "{$themePath}/config.php";
        
        return [
            'name' => $theme,
            'path' => $themePath,
            'config' => $config,
            'layouts' => $this->scanThemeLayouts($themePath),
            'components' => $this->scanThemeComponents($themePath)
        ];
    }

    private function compileTemplate(string $content): string
    {
        // Replace template directives with PHP code
        $content = $this->compileIncludes($content);
        $content = $this->compileComponents($content);
        $content = $this->compileEchos($content);
        $content = $this->compileStatements($content);

        // Add security headers
        $content = "<?php if (!defined('CMS_SECURE')) exit; ?>\n" . $content;

        return $content;
    }

    private function validateCompiledTemplate(string $compiled): void
    {
        // Check for potential security issues
        if (preg_match('/(eval|exec|system|passthru)\s*\(/i', $compiled)) {
            throw new SecurityException('Potential security risk in template');
        }

        // Validate PHP syntax
        if (!$this->validatePHPSyntax($compiled)) {
            throw new TemplateException('Invalid PHP syntax in compiled template');
        }
    }

    private function renderCompiled(string $compiled, array $data, array $options): string
    {
        // Create isolated scope for rendering
        extract($data);
        
        ob_start();
        try {
            eval('?>' . $compiled);
            $output = ob_get_contents();
        } catch (\Throwable $e) {
            throw new RenderException('Template rendering failed: ' . $e->getMessage());
        } finally {
            ob_end_clean();
        }

        // Post-process output
        if ($options['minify'] ?? false) {
            $output = $this->minifyOutput($output);
        }

        return $output;
    }

    private function processInheritance(string $content): string
    {
        if (preg_match('/@extends\([\'"]([^\'"]+)[\'"]\)/', $content, $matches)) {
            $parentTemplate = $this->loadTemplate($matches[1]);
            $content = $this->mergeTemplates($parentTemplate, $content);
        }

        return $content;
    }

    private function loadTemplate(string $name): string
    {
        return $this->cache->remember(
            "template:{$name}",
            now()->addHour(),
            function() use ($name) {
                $path = $this->resolveTemplatePath($name);
                if (!file_exists($path)) {
                    throw new TemplateException("Template file not found: {$name}");
                }
                return file_get_contents($path);
            }
        );
    }

    private function resolveTemplatePath(string $name): string
    {
        return $this->activeTheme['path'] . '/templates/' . $name . '.blade.php';
    }

    private function sanitizeData(array $data): array
    {
        return array_map(function($value) {
            if (is_string($value)) {
                return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            }
            return $value;
        }, $data);
    }

    private function validatePHPSyntax(string $code): bool
    {
        return @token_get_all($code, TOKEN_PARSE) !== false;
    }

    private function minifyOutput(string $output): string
    {
        // Basic minification
        $output = preg_replace('/\s+/', ' ', $output);
        $output = preg_replace('/>\s+</', '><', $output);
        return trim($output);
    }

    private function scanThemeLayouts(string $themePath): array
    {
        $layouts = [];
        $layoutPath = "{$themePath}/layouts";
        
        if (is_dir($layoutPath)) {
            $files = scandir($layoutPath);
            foreach ($files as $file) {
                if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                    $layouts[] = pathinfo($file, PATHINFO_FILENAME);
                }
            }
        }

        return $layouts;
    }

    private function scanThemeComponents(string $themePath): array
    {
        $components = [];
        $componentPath = "{$themePath}/components";
        
        if (is_dir($componentPath)) {
            $files = scandir($componentPath);
            foreach ($files as $file) {
                if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                    $components[] = pathinfo($file, PATHINFO_FILENAME);
                }
            }
        }

        return $components;
    }
}
