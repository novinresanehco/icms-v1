<?php

namespace App\Core\Template;

use Illuminate\Support\Facades\{Cache, View, File};
use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManagerInterface;
use App\Core\Exceptions\{TemplateException, SecurityException};

class TemplateManager implements TemplateManagerInterface
{
    private SecurityManagerInterface $security;
    private CacheManagerInterface $cache;
    private ComponentRegistry $components;
    private ThemeManager $themes;
    
    private const CACHE_TTL = 3600; // 1 hour
    
    public function __construct(
        SecurityManagerInterface $security,
        CacheManagerInterface $cache,
        ComponentRegistry $components,
        ThemeManager $themes
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->components = $components;
        $this->themes = $themes;
    }

    public function render(string $template, array $data = [], ?string $theme = null): string 
    {
        return $this->security->executeCriticalOperation(function() use ($template, $data, $theme) {
            // Validate template and data
            $this->validateTemplate($template);
            $this->validateData($data);
            
            // Get active theme or use specified theme
            $theme = $theme ?? $this->themes->getActiveTheme();
            
            $cacheKey = $this->getCacheKey($template, $data, $theme);
            
            return $this->cache->remember($cacheKey, self::CACHE_TTL, function() use ($template, $data, $theme) {
                // Process template with theme
                $processed = $this->processTemplate($template, $theme);
                
                // Compile components
                $processed = $this->compileComponents($processed);
                
                // Render with data
                return $this->renderTemplate($processed, $data);
            });
        });
    }

    public function registerComponent(string $name, Component $component): void 
    {
        $this->security->executeCriticalOperation(function() use ($name, $component) {
            // Validate component
            if (!$this->validateComponent($component)) {
                throw new TemplateException("Invalid component: {$name}");
            }
            
            $this->components->register($name, $component);
            
            // Clear component cache
            $this->cache->tags(['components'])->flush();
        });
    }

    public function compileTemplate(string $template): CompiledTemplate 
    {
        return $this->security->executeCriticalOperation(function() use ($template) {
            // Validate and sanitize template
            $this->validateTemplate($template);
            
            // Parse template structure
            $structure = $this->parseTemplate($template);
            
            // Validate template structure
            $this->validateStructure($structure);
            
            // Compile template
            $compiled = $this->doCompilation($structure);
            
            // Validate compiled output
            $this->validateCompiled($compiled);
            
            return new CompiledTemplate($compiled);
        });
    }

    protected function processTemplate(string $template, Theme $theme): string 
    {
        // Apply theme layouts
        $processed = $this->themes->applyLayout($template, $theme);
        
        // Process theme includes
        $processed = $this->processIncludes($processed, $theme);
        
        // Apply theme styles
        $processed = $this->themes->applyStyles($processed, $theme);
        
        return $processed;
    }

    protected function compileComponents(string $template): string 
    {
        preg_match_all('/<x-([^>\s]+)([^>]*)>/s', $template, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $component = $this->components->get($match[1]);
            if (!$component) {
                throw new TemplateException("Component not found: {$match[1]}");
            }
            
            // Parse component attributes
            $attributes = $this->parseAttributes($match[2]);
            
            // Validate component attributes
            $this->validateComponentAttributes($component, $attributes);
            
            // Render component
            $rendered = $component->render($attributes);
            
            // Replace in template
            $template = str_replace($match[0], $rendered, $template);
        }
        
        return $template;
    }

    protected function renderTemplate(string $template, array $data): string 
    {
        try {
            return View::make('string:' . $template, $data)->render();
        } catch (\Exception $e) {
            throw new TemplateException('Failed to render template: ' . $e->getMessage());
        }
    }

    protected function validateTemplate(string $template): void 
    {
        if (empty($template)) {
            throw new TemplateException('Empty template');
        }
        
        // Check for malicious code
        if ($this->containsMaliciousCode($template)) {
            throw new SecurityException('Malicious code detected in template');
        }
        
        // Validate template syntax
        if (!$this->validateSyntax($template)) {
            throw new TemplateException('Invalid template syntax');
        }
    }

    protected function validateData(array $data): void 
    {
        foreach ($data as $key => $value) {
            if (!$this->isValidDataType($value)) {
                throw new TemplateException("Invalid data type for key: {$key}");
            }
        }
    }

    protected function validateComponent(Component $component): bool 
    {
        return $component instanceof ComponentInterface &&
               method_exists($component, 'render') &&
               method_exists($component, 'validate');
    }

    protected function containsMaliciousCode(string $content): bool 
    {
        $patterns = [
            '/\<\?php/i',
            '/\<\?[\s]*=/i',
            '/\<\?[\s]*echo/i',
            '/eval[\s]*\(/i',
            '/exec[\s]*\(/i',
            '/system[\s]*\(/i',
            '/passthru[\s]*\(/i',
            '/shell_exec[\s]*\(/i',
            '/`.*`/'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }
        
        return false;
    }

    protected function getCacheKey(string $template, array $data, Theme $theme): string 
    {
        return 'template:' . md5($template . serialize($data) . $theme->getId());
    }
}
