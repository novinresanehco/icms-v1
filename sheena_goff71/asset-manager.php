<?php

namespace App\Core\Template\Assets;

class AssetManager implements AssetInterface 
{
    private SecurityManager $security;
    private CacheManager $cache;
    private array $config;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        array $config
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function processAssets(array $assets, string $context): array 
    {
        return DB::transaction(function() use ($assets, $context) {
            $this->security->validateAssetContext($context);
            
            return array_map(
                fn($asset) => $this->processAsset($asset, $context),
                $assets
            );
        });
    }

    private function processAsset(Asset $asset, string $context): ProcessedAsset 
    {
        $processor = $this->getProcessor($asset->getType());
        
        $processed = $processor->process(
            $asset,
            $this->getProcessingOptions($context)
        );
        
        $this->validateProcessedAsset($processed);
        
        return $processed;
    }

    private function getProcessor(string $type): AssetProcessorInterface 
    {
        return match($type) {
            'image' => new ImageProcessor($this->security),
            'script' => new ScriptProcessor($this->security),
            'style' => new StyleProcessor($this->security),
            default => throw new UnsupportedAssetType($type)
        };
    }

    private function validateProcessedAsset(ProcessedAsset $asset): void 
    {
        if (!$this->security->validateAssetIntegrity($asset)) {
            throw new AssetIntegrityException($asset->getId());
        }
    }

    private function getProcessingOptions(string $context): array 
    {
        return [
            'security_level' => $this->config['security_level'],
            'optimization_level' => $this->config['optimization_level'],
            'context' => $context
        ];
    }
}

class ImageProcessor implements AssetProcessorInterface 
{
    private SecurityManager $security;
    
    public function process(Asset $asset, array $options): ProcessedAsset 
    {
        $this->security->validateImageAsset($asset);
        
        return new ProcessedAsset(
            $asset->getId(),
            $this->optimizeImage($asset, $options),
            $this->generateIntegrityHash($asset)
        );
    }

    private function optimizeImage(Asset $asset, array $options): string 
    {
        $image = $asset->getContent();
        
        if ($options['optimization_level'] > 0) {
            $image = $this->compress($image, $options['optimization_level']);
        }
        
        return $image;
    }
}

class ScriptProcessor implements AssetProcessorInterface 
{
    private SecurityManager $security;
    
    public function process(Asset $asset, array $options): ProcessedAsset 
    {
        $this->security->validateScriptAsset($asset);
        
        return new ProcessedAsset(
            $asset->getId(),
            $this->processScript($asset, $options),
            $this->generateIntegrityHash($asset)
        );
    }

    private function processScript(Asset $asset, array $options): string 
    {
        $script = $asset->getContent();
        
        if ($options['security_level'] === 'high') {
            $script = $this->security->sanitizeScript($script);
        }
        
        if ($options['optimization_level'] > 0) {
            $script = $this->minify($script);
        }
        
        return $script;
    }
}

class StyleProcessor implements AssetProcessorInterface 
{
    private SecurityManager $security;
    
    public function process(Asset $asset, array $options): ProcessedAsset 
    {
        $this->security->validateStyleAsset($asset);
        
        return new ProcessedAsset(
            $asset->getId(),
            $this->processStyle($asset, $options),
            $this->generateIntegrityHash($asset)
        );
    }

    private function processStyle(Asset $asset, array $options): string 
    {
        $style = $asset->getContent();
        
        if ($options['security_level'] === 'high') {
            $style = $this->security->sanitizeStyle($style);
        }
        
        if ($options['optimization_level'] > 0) {
            $style = $this->minify($style);
        }
        
        return $style;
    }
}

interface AssetInterface 
{
    public function processAssets(array $assets, string $context): array;
}

interface AssetProcessorInterface 
{
    public function process(Asset $asset, array $options): ProcessedAsset;
}
