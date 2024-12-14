<?php

namespace App\Core\Template\Performance;

class TemplateCache implements TemplateCacheInterface 
{
    private CacheManagerInterface $cache;
    private SecurityManagerInterface $security;
    private TemplateCompilerInterface $compiler;

    public function compile(string $template, array $data): string 
    {
        $cacheKey = $this->generateCacheKey($template, $data);
        
        return $this->cache->remember($cacheKey, function() use ($template, $data) {
            $compiled = $this->compiler->compile(
                $this->security->validateTemplate($template),
                $this->security->sanitizeData($data)
            );
            
            $this->security->validateOutput($compiled);
            return $compiled;
        }, $this->determineCacheTTL($template));
    }

    private function generateCacheKey(string $template, array $data): string 
    {
        return sprintf(
            'template:%s:%s:%s',
            $template,
            $this->security->getCurrentRole(),
            md5(serialize($data))
        );
    }

    private function determineCacheTTL(string $template): int 
    {
        return match($this->compiler->getTemplateType($template)) {
            'static' => 3600,
            'dynamic' => 300,
            'critical' => 60,
            default => 600
        };
    }
}

class TemplateOptimizer implements TemplateOptimizerInterface 
{
    private SecurityManagerInterface $security;
    private array $optimizers = [];

    public function optimize(string $content, array $options = []): string 
    {
        $this->security->validateContent($content);
        
        $optimized = array_reduce(
            $this->optimizers,
            fn($content, $optimizer) => $optimizer->process($content, $options),
            $content
        );

        return $this->security->validateOutput($optimized);
    }

    public function registerOptimizer(OptimizerInterface $optimizer): void 
    {
        $this->optimizers[] = $optimizer;
    }
}

class TemplateProcessor implements TemplateProcessorInterface 
{
    private TemplateCacheInterface $cache;
    private TemplateOptimizerInterface $optimizer;
    private SecurityManagerInterface $security;

    public function process(string $template, array $data = []): string 
    {
        try {
            $compiled = $this->cache->compile($template, $data);
            $optimized = $this->optimizer->optimize($compiled);
            
            $this->security->validateFinalOutput($optimized);
            return $optimized;
            
        } catch (TemplateException $e) {
            throw new ProcessingException("Failed to process template: {$template}", 0, $e);
        }
    }
}

interface TemplateCacheInterface {
    public function compile(string $template, array $data): string;
}

interface TemplateOptimizerInterface {
    public function optimize(string $content, array $options = []): string;
    public function registerOptimizer(OptimizerInterface $optimizer): void;
}

interface TemplateProcessorInterface {
    public function process(string $template, array $data = []): string;
}

interface OptimizerInterface {
    public function process(string $content, array $options = []): string;
}

class ProcessingException extends \RuntimeException {}
