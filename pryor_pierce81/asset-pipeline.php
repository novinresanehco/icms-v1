<?php

namespace App\Core\Template\Assets;

class AssetPipelineManager
{
    protected array $config;
    protected AssetCompiler $compiler;
    protected CacheManager $cache;
    protected Filesystem $filesystem;
    
    /**
     * Process assets through pipeline
     */
    public function process(array $assets, string $type): ProcessedAsset
    {
        // Generate unique hash for asset combination
        $hash = $this->generateHash($assets);
        
        // Check cache first
        if ($cached = $this->cache->get("assets:{$type}:{$hash}")) {
            return new ProcessedAsset($cached, $hash);
        }
        
        // Process assets based on type
        $processed = match($type) {
            'css' => $this->processCssAssets($assets),
            'js'  => $this->processJsAssets($assets),
            default => throw new InvalidAssetTypeException("Unsupported asset type: {$type}")
        };
        
        // Store in cache
        $this->cache->put("assets:{$type}:{$hash}", $processed, $this->config['cache_ttl']);
        
        return new ProcessedAsset($processed, $hash);
    }
    
    /**
     * Process CSS assets
     */
    protected function processCssAssets(array $assets): string
    {
        $content = '';
        
        foreach ($assets as $asset) {
            $css = $this->filesystem->get($asset);
            
            // Process imports and urls
            $css = $this->processImports($css, dirname($asset));
            $css = $this->processUrls($css, dirname($asset));
            
            // Minify if enabled
            if ($this->config['minify_css']) {
                $css = $this->compiler->minifyCss($css);
            }
            
            $content .= $css . "\n";
        }
        
        return $content;
    }
    
    /**
     * Process JavaScript assets
     */
    protected function processJsAssets(array $assets): string
    {
        $content = '';
        
        foreach ($assets as $asset) {
            $js = $this->filesystem->get($asset);
            
            // Process modules if needed
            if ($this->isModule($asset)) {
                $js = $this->processModule($js);
            }
            
            // Minify if enabled
            if ($this->config['minify_js']) {
                $js = $this->compiler->minifyJs($js);
            }
            
            $content .= $js . ";\n";
        }
        
        return $content;
    }
}

namespace App\Core\Template\Assets;

class AssetCompiler
{
    protected array $compressors;
    protected array $config;
    
    /**
     * Compile asset with source maps
     */
    public function compile(string $content, string $type, bool $sourceMap = false): CompiledAsset
    {
        $compiled = $this->getCompressor($type)->compress($content);
        
        if ($sourceMap) {
            $sourceMap = $this->generateSourceMap($content, $compiled);
            return new CompiledAsset($compiled, $sourceMap);
        }
        
        return new CompiledAsset($compiled);
    }
    
    /**
     * Generate source map
     */
    protected function generateSourceMap(string $original, string $compiled): string
    {
        return $this->getCompressor('js')->generateMap(
            $original,
            $compiled,
            ['source' => 'original.js']
        );
    }
    
    /**
     * Get appropriate compressor
     */
    protected function getCompressor(string $type): CompressorInterface
    {
        if (!isset($this->compressors[$type])) {
            $this->compressors[$type] = $this->createCompressor($type);
        }
        
        return $this->compressors[$type];
    }
}

namespace App\Core\Template\Assets;

class ResourceManager
{
    protected array $resources = [];
    protected array $dependencies = [];
    protected DependencyResolver $resolver;
    
    /**
     * Register a new resource
     */
    public function register(string $name, string $path, array $dependencies = []): void
    {
        $this->resources[$name] = [
            'path' => $path,
            'type' => $this->getResourceType($path)
        ];
        
        if (!empty($dependencies)) {
            $this->dependencies[$name] = $dependencies;
        }
    }
    
    /**
     * Get resolved resource list
     */
    public function resolve(array $resources): array
    {
        // Build dependency graph
        $graph = $this->buildDependencyGraph($resources);
        
        // Resolve dependencies
        $resolved = $this->resolver->resolve($graph);
        
        // Map to resource paths
        return array_map(function($name) {
            return $this->resources[$name]['path'];
        }, $resolved);
    }
    
    /**
     * Build dependency graph
     */
    protected function buildDependencyGraph(array $resources): array
    {
        $graph = [];
        
        foreach ($resources as $resource) {
            $graph[$resource] = $this->dependencies[$resource] ?? [];
        }
        
        return $graph;
    }
}

namespace App\Core\Template\Assets;

class DependencyResolver
{
    protected array $resolved = [];
    protected array $unresolved = [];
    
    /**
     * Resolve dependencies
     */
    public function resolve(array $graph): array
    {
        $this->resolved = [];
        $this->unresolved = [];
        
        foreach (array_keys($graph) as $node) {
            $this->resolveNode($node, $graph);
        }
        
        return $this->resolved;
    }
    
    /**
     * Resolve single node
     */
    protected function resolveNode(string $node, array $graph): void
    {
        // Check for circular dependencies
        if (in_array($node, $this->unresolved)) {
            throw new CircularDependencyException(
                "Circular dependency detected: {$node}"
            );
        }
        
        if (!in_array($node, $this->resolved)) {
            $this->unresolved[] = $node;
            
            foreach ($graph[$node] as $dependency) {
                $this->resolveNode($dependency, $graph);
            }
            
            $this->resolved[] = $node;
            $this->unresolved = array_diff($this->unresolved, [$node]);
        }
    }
}

namespace App\Core\Template\Assets;

class ResourceOptimizer
{
    protected ImageOptimizer $imageOptimizer;
    protected FontOptimizer $fontOptimizer;
    protected CacheManager $cache;
    
    /**
     * Optimize resource
     */
    public function optimize(string $path): OptimizedResource
    {
        $type = $this->getResourceType($path);
        
        $optimized = match($type) {
            'image' => $this->optimizeImage($path),
            'font'  => $this->optimizeFont($path),
            default => $this->optimizeGeneric($path)
        };
        
        return new OptimizedResource(
            $optimized['path'],
            $optimized['size'],
            $optimized['hash']
        );
    }
    
    /**
     * Optimize image resource
     */
    protected function optimizeImage(string $path): array
    {
        $hash = md5_file($path);
        $cached = $this->cache->get("image_optimize:{$hash}");
        
        if ($cached) {
            return $cached;
        }
        
        $optimized = $this->imageOptimizer->optimize($path, [
            'quality' => $this->config['image_quality'],
            'strip_metadata' => true
        ]);
        
        $this->cache->put("image_optimize:{$hash}", $optimized);
        
        return $optimized;
    }
    
    /**
     * Optimize font resource
     */
    protected function optimizeFont(string $path): array
    {
        return $this->fontOptimizer->optimize($path, [
            'subset' => $this->config['font_subset'],
            'formats' => ['woff2', 'woff']
        ]);
    }
}
