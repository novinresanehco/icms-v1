<?php

namespace App\Core\Template\Inheritance;

class TemplateInheritance
{
    protected array $stack = [];
    protected array $blockStorage = [];
    protected ?string $currentBlock = null;
    
    /**
     * Start extending a parent template
     */
    public function startExtend(string $parent): void
    {
        $this->stack[] = [
            'parent' => $parent,
            'blocks' => []
        ];
    }
    
    /**
     * Start a new block definition
     */
    public function startBlock(string $name): void
    {
        if ($this->currentBlock) {
            throw new TemplateException('Cannot nest blocks');
        }
        
        $this->currentBlock = $name;
        ob_start();
    }
    
    /**
     * End current block definition
     */
    public function endBlock(): void
    {
        if (!$this->currentBlock) {
            throw new TemplateException('No block started');
        }
        
        $content = ob_get_clean();
        $this->storeBlock($this->currentBlock, $content);
        $this->currentBlock = null;
    }
    
    /**
     * Store block content
     */
    protected function storeBlock(string $name, string $content): void
    {
        if (!empty($this->stack)) {
            $current = &$this->stack[count($this->stack) - 1];
            $current['blocks'][$name] = $content;
        }
        
        $this->blockStorage[$name] = $content;
    }
    
    /**
     * Get block content
     */
    public function getBlock(string $name): ?string
    {
        return $this->blockStorage[$name] ?? null;
    }
}

namespace App\Core\Template\Cache;

class AdvancedTemplateCache
{
    protected CacheManager $cache;
    protected Filesystem $filesystem;
    protected array $config;
    
    /**
     * Store compiled template with dependencies
     */
    public function store(string $key, string $content, array $dependencies = []): void
    {
        $hash = $this->hashDependencies($dependencies);
        
        $this->cache->tags(['templates'])->put($key, [
            'content' => $content,
            'hash' => $hash,
            'dependencies' => $dependencies
        ], $this->getTtl());
    }
    
    /**
     * Get compiled template if valid
     */
    public function get(string $key, array $dependencies = []): ?string
    {
        $cached = $this->cache->tags(['templates'])->get($key);
        
        if (!$cached) {
            return null;
        }
        
        $currentHash = $this->hashDependencies($dependencies);
        
        if ($cached['hash'] !== $currentHash) {
            $this->cache->tags(['templates'])->forget($key);
            return null;
        }
        
        return $cached['content'];
    }
    
    /**
     * Hash dependencies for comparison
     */
    protected function hashDependencies(array $dependencies): string
    {
        $timestamps = [];
        
        foreach ($dependencies as $file) {
            if ($this->filesystem->exists($file)) {
                $timestamps[$file] = $this->filesystem->lastModified($file);
            }
        }
        
        return md5(serialize($timestamps));
    }
    
    /**
     * Clear cached templates by pattern
     */
    public function clearPattern(string $pattern): void
    {
        $keys = $this->cache->tags(['templates'])
            ->get('__keys__', []);
            
        foreach ($keys as $key) {
            if (str_is($pattern, $key)) {
                $this->cache->tags(['templates'])->forget($key);
            }
        }
    }
}

namespace App\Core\Template\Optimization;

class TemplateOptimizer
{
    protected array $config;
    
    /**
     * Optimize template content
     */
    public function optimize(string $content): string
    {
        $content = $this->removeComments($content);
        $content = $this->removeWhitespace($content);
        $content = $this->optimizeHtml($content);
        
        return $content;
    }
    
    /**
     * Remove template comments
     */
    protected function removeComments(string $content): string
    {
        return preg_replace('/\{\{--.*?--\}\}/s', '', $content);
    }
    
    /**
     * Remove excessive whitespace
     */
    protected function removeWhitespace(string $content): string
    {
        if (!$this->config['compress_html']) {
            return $content;
        }
        
        $replace = [
            '/\>[^\S ]+/s'  => '>',
            '/[^\S ]+\</s'  => '<',
            '/(\s)+/s'      => '\\1'
        ];
        
        return preg_replace(array_keys($replace), array_values($replace), $content);
    }
    
    /**
     * Optimize HTML output
     */
    protected function optimizeHtml(string $content): string
    {
        if (!$this->config['optimize_html']) {
            return $content;
        }
        
        // Remove optional HTML tags
        $content = preg_replace([
            '/<\/option>/',
            '/<\/li>/',
            '/<\/dt>/',
            '/<\/dd>/',
            '/<\/p>/'
        ], '', $content);
        
        return $content;
    }
}

namespace App\Core\Template\Debug;

class TemplateDebugger
{
    protected array $traces = [];
    protected bool $enabled;
    
    /**
     * Add debug trace
     */
    public function addTrace(string $template, array $data): void
    {
        if (!$this->enabled) {
            return;
        }
        
        $this->traces[] = [
            'template' => $template,
            'data' => $data,
            'time' => microtime(true),
            'memory' => memory_get_usage(true)
        ];
    }
    
    /**
     * Get debug information
     */
    public function getDebugInfo(): array
    {
        if (!$this->enabled) {
            return [];
        }
        
        $traces = $this->traces;
        $totalTime = 0;
        $totalMemory = 0;
        
        foreach ($traces as &$trace) {
            $trace['memory_formatted'] = $this->formatBytes($trace['memory']);
            $totalMemory += $trace['memory'];
            $totalTime += ($trace['time'] - $traces[0]['time']);
        }
        
        return [
            'traces' => $traces,
            'total_time' => round($totalTime * 1000, 2),
            'total_memory' => $this->formatBytes($totalMemory),
            'total_templates' => count($traces)
        ];
    }
    
    /**
     * Format bytes to human readable format
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
