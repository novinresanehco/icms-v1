<?php

namespace App\Core\Template\Security;

class TemplateSecurity
{
    protected array $config;
    protected array $allowedTags;
    protected array $allowedAttributes;
    protected array $allowedFunctions;
    
    public function __construct()
    {
        $this->config = config('template.security');
        $this->allowedTags = $this->config['allowed_tags'] ?? [];
        $this->allowedAttributes = $this->config['allowed_attributes'] ?? [];
        $this->allowedFunctions = $this->config['allowed_functions'] ?? [];
    }
    
    /**
     * Sanitize template content
     */
    public function sanitize(string $content): string
    {
        $content = $this->sanitizePHP($content);
        $content = $this->sanitizeHTML($content);
        $content = $this->sanitizeAttributes($content);
        $content = $this->sanitizeScripts($content);
        
        return $content;
    }
    
    /**
     * Sanitize PHP code in templates
     */
    protected function sanitizePHP(string $content): string
    {
        // Remove PHP open/close tags except for allowed syntax
        $content = preg_replace(
            '/<\?(?!php|=).*?\?>/is',
            '',
            $content
        );
        
        // Filter allowed PHP functions
        return preg_replace_callback(
            '/\{\{ *([a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*)\(.*?\) *\}\}/i',
            function($matches) {
                if (!in_array($matches[1], $this->allowedFunctions)) {
                    return '';
                }
                return $matches[0];
            },
            $content
        );
    }
    
    /**
     * Sanitize HTML content
     */
    protected function sanitizeHTML(string $content): string
    {
        return strip_tags($content, $this->allowedTags);
    }
    
    /**
     * Sanitize HTML attributes
     */
    protected function sanitizeAttributes(string $content): string
    {
        return preg_replace_callback(
            '/<([a-z][a-z0-9]*)[^>]*?(\/?)>/i',
            function($matches) {
                $tag = $matches[1];
                
                if (!in_array($tag, $this->allowedTags)) {
                    return '';
                }
                
                // Extract and filter attributes
                preg_match_all(
                    '/([a-z-]+)="([^"]*?)"/i',
                    $matches[0],
                    $attributes
                );
                
                $cleanAttributes = [];
                foreach ($attributes[1] as $i => $name) {
                    if (in_array($name, $this->allowedAttributes)) {
                        $cleanAttributes[] = $name . '="' . 
                            htmlspecialchars($attributes[2][$i]) . '"';
                    }
                }
                
                return '<' . $tag . 
                       (!empty($cleanAttributes) ? ' ' . implode(' ', $cleanAttributes) : '') . 
                       $matches[2] . '>';
            },
            $content
        );
    }
}

namespace App\Core\Template\Validation;

class TemplateValidator
{
    protected array $rules = [];
    protected array $errors = [];
    
    /**
     * Validate template content
     */
    public function validate(string $content): bool
    {
        $this->errors = [];
        
        // Check template syntax
        if (!$this->validateSyntax($content)) {
            return false;
        }
        
        // Check template structure
        if (!$this->validateStructure($content)) {
            return false;
        }
        
        // Validate custom rules
        foreach ($this->rules as $rule) {
            if (!$rule->validate($content)) {
                $this->errors[] = $rule->getMessage();
            }
        }
        
        return empty($this->errors);
    }
    
    /**
     * Validate template syntax
     */
    protected function validateSyntax(string $content): bool
    {
        try {
            // Check for matching brackets
            if (!$this->checkMatchingBrackets($content)) {
                $this->errors[] = 'Unmatched template brackets found';
                return false;
            }
            
            // Check for valid PHP syntax in template expressions
            if (!$this->checkPHPSyntax($content)) {
                $this->errors[] = 'Invalid PHP syntax in template';
                return false;
            }
            
            return true;
        } catch (\Exception $e) {
            $this->errors[] = 'Syntax error: ' . $e->getMessage();
            return false;
        }
    }
    
    /**
     * Check for matching brackets
     */
    protected function checkMatchingBrackets(string $content): bool
    {
        $brackets = [
            '{{' => '}}',
            '{!!' => '!!}',
            '@if(' => ')',
            '@foreach(' => ')',
            '@while(' => ')'
        ];
        
        foreach ($brackets as $open => $close) {
            if (substr_count($content, $open) !== substr_count($content, $close)) {
                return false;
            }
        }
        
        return true;
    }
}

namespace App\Core\Template\Precompilation;

class TemplatePrecompiler
{
    protected TemplateCompiler $compiler;
    protected TemplateCache $cache;
    protected array $config;
    
    /**
     * Precompile all templates
     */
    public function precompileAll(): array
    {
        $results = [];
        $templates = $this->getTemplateFiles();
        
        foreach ($templates as $template) {
            try {
                $results[$template] = $this->precompile($template);
            } catch (\Exception $e) {
                $results[$template] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Precompile single template
     */
    public function precompile(string $template): array
    {
        $content = $this->filesystem->get($template);
        
        // Validate template
        if (!$this->validator->validate($content)) {
            throw new TemplateException(
                'Template validation failed: ' . implode(', ', $this->validator->getErrors())
            );
        }
        
        // Compile template
        $compiled = $this->compiler->compile($content);
        
        // Store in cache
        $this->cache->store(
            $this->getCacheKey($template),
            $compiled,
            [$template]
        );
        
        return [
            'success' => true,
            'template' => $template,
            'size' => strlen($compiled)
        ];
    }
}

namespace App\Core\Template\Profiling;

class TemplateProfiler
{
    protected array $profiles = [];
    protected float $startTime;
    protected array $metrics = [];
    
    /**
     * Start profiling
     */
    public function start(string $template): void
    {
        $this->startTime = microtime(true);
        $this->metrics = [
            'memory_start' => memory_get_usage(),
            'template' => $template
        ];
    }
    
    /**
     * End profiling
     */
    public function end(): void
    {
        $this->metrics['duration'] = microtime(true) - $this->startTime;
        $this->metrics['memory_peak'] = memory_get_peak_usage();
        $this->metrics['memory_used'] = memory_get_usage() - $this->metrics['memory_start'];
        
        $this->profiles[] = $this->metrics;
    }
    
    /**
     * Get profiling report
     */
    public function getReport(): array
    {
        $totalTime = 0;
        $totalMemory = 0;
        
        foreach ($this->profiles as $profile) {
            $totalTime += $profile['duration'];
            $totalMemory += $profile['memory_used'];
        }
        
        return [
            'profiles' => $this->profiles,
            'total_time' => $totalTime,
            'total_memory' => $totalMemory,
            'average_time' => $totalTime / count($this->profiles),
            'average_memory' => $totalMemory / count($this->profiles)
        ];
    }
}
