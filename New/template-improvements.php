<?php

namespace App\Core\Template\Compilation;

class TemplateValidator
{
    private array $rules = [];
    private array $errors = [];

    public function addRule(string $name, callable $validator): void
    {
        $this->rules[$name] = $validator;
    }

    public function validate(string $content): bool
    {
        $this->errors = [];
        
        foreach ($this->rules as $name => $validator) {
            if (!$validator($content)) {
                $this->errors[] = "Validation failed: {$name}";
            }
        }
        
        return empty($this->errors);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}

class TemplateCacheManager
{
    private string $cachePath;
    private int $cacheLifetime;

    public function __construct(string $cachePath, int $cacheLifetime = 3600)
    {
        $this->cachePath = $cachePath;
        $this->cacheLifetime = $cacheLifetime;
    }

    public function getCached(string $template): ?string
    {
        $path = $this->getCachePath($template);
        
        if (!$this->isValidCache($path)) {
            return null;
        }
        
        return file_get_contents($path);
    }

    public function cache(string $template, string $content): void
    {
        $path = $this->getCachePath($template);
        file_put_contents($path, $content);
    }

    private function isValidCache(string $path): bool
    {
        if (!file_exists($path)) {
            return false;
        }

        $modifiedTime = filemtime($path);
        return (time() - $modifiedTime) < $this->cacheLifetime;
    }

    private function getCachePath(string $template): string
    {
        return $this->cachePath . '/' . hash('sha256', $template) . '.cache';
    }
}

class TemplatePerformanceMonitor
{
    private array $metrics = [];
    private float $startTime;

    public function startOperation(string $operation): void
    {
        $this->startTime = microtime(true);
        $this->metrics[$operation] = [
            'start' => $this->startTime,
            'memory_start' => memory_get_usage()
        ];
    }

    public function endOperation(string $operation): array
    {
        $endTime = microtime(true);
        $this->metrics[$operation]['end'] = $endTime;
        $this->metrics[$operation]['memory_end'] = memory_get_usage();
        $this->metrics[$operation]['duration'] = $endTime - $this->metrics[$operation]['start'];
        $this->metrics[$operation]['memory_usage'] = 
            $this->metrics[$operation]['memory_end'] - $this->metrics[$operation]['memory_start'];
            
        return $this->metrics[$operation];
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }
}

// Enhanced TemplateCompiler with new features
class EnhancedTemplateCompiler extends TemplateCompiler
{
    private TemplateValidator $validator;
    private TemplateCacheManager $cache;
    private TemplatePerformanceMonitor $monitor;
    
    public function __construct(
        SecurityManagerInterface $security,
        string $compilePath,
        TemplateValidator $validator,
        TemplateCacheManager $cache,
        TemplatePerformanceMonitor $monitor
    ) {
        parent::__construct($security, $compilePath);
        $this->validator = $validator;
        $this->cache = $cache;
        $this->monitor = $monitor;
    }

    public function compile(string $template): CompiledTemplate
    {
        $this->monitor->startOperation('compile');
        
        try {
            // Check cache first
            if ($cached = $this->cache->getCached($template)) {
                $this->monitor->endOperation('compile');
                return new CompiledTemplate($cached);
            }

            // Compile template
            $compiled = parent::compile($template);
            
            // Validate compiled content
            if (!$this->validator->validate($compiled->getPath())) {
                throw new CompilationException(
                    "Template validation failed: " . 
                    implode(", ", $this->validator->getErrors())
                );
            }

            // Cache the result
            $this->cache->cache($template, $compiled->getPath());
            
            $metrics = $this->monitor->endOperation('compile');
            error_log("Template compilation metrics: " . json_encode($metrics));
            
            return $compiled;
        } catch (\Exception $e) {
            $this->monitor->endOperation('compile');
            throw $e;
        }
    }
}
