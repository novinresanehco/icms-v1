<?php

namespace App\Core\Template\Image;

class ImageProcessor implements ImageProcessorInterface
{
    private SecurityManager $security;
    private ImageOptimizer $optimizer;
    private ImageResizer $resizer;
    private CacheManager $cache;
    private Config $config;

    public function process(Image $image, ProcessingContext $context): ProcessedImage
    {
        try {
            // Security validation
            $this->validateImage($image);

            // Get from cache if available
            $cacheKey = $this->getCacheKey($image, $context);
            if ($cached = $this->cache->get($cacheKey)) {
                return $cached;
            }

            // Process image
            $processed = $this->processImage($image, $context);

            // Cache result
            $this->cache->put($cacheKey, $processed);

            return $processed;

        } catch (\Exception $e) {
            $this->handleProcessingError($image, $e);
            throw $e;
        }
    }

    private function processImage(Image $image, ProcessingContext $context): ProcessedImage
    {
        // Strip metadata
        $image->stripMetadata();

        // Optimize image
        $optimized = $this->optimizer->optimize($image, [
            'quality' => $this->config->get('image.quality'),
            'format' => $this->determineOptimalFormat($image)
        ]);

        // Generate variants
        $variants = $this->generateVariants($optimized, $context);

        return new ProcessedImage($optimized, $variants);
    }

    private function generateVariants(Image $image, ProcessingContext $context): array
    {
        $variants = [];
        foreach ($this->config->getImageVariants() as $variant => $specs) {
            $variants[$variant] = $this->resizer->resize($image, [
                'width' => $specs['width'],
                'height' => $specs['height'],
                'mode' => $specs['mode']
            ]);
        }
        return $variants;
    }

    private function validateImage(Image $image): void
    {
        // Security validation
        if (!$this->security->validateImage($image)) {
            throw new SecurityException('Image validation failed');
        }

        // Size validation
        $maxSize = $this->config->get('image.max_size');
        if ($image->getSize() > $maxSize) {
            throw new ValidationException('Image size exceeds limit');
        }

        // Format validation
        $allowedFormats = $this->config->get('image.allowed_formats');
        if (!in_array($image->getFormat(), $allowedFormats)) {
            throw new ValidationException('Invalid image format');
        }
    }

    private function determineOptimalFormat(Image $image): string
    {
        // Check browser support
        if ($this->supportsWebP()) {
            return 'webp';
        }

        // Fallback to original format
        return $image->getFormat();
    }
}

class ImageOptimizer implements OptimizerInterface
{
    private array $optimizers;
    private Config $config;

    public function optimize(Image $image, array $options = []): OptimizedImage
    {
        // Select optimizer
        $optimizer = $this->selectOptimizer($image);

        // Apply optimizations
        $optimized = $optimizer->optimize($image, [
            'quality' => $options['quality'] ?? $this->config->get('image.quality'),
            'progressive' => true,
            'strip' => true
        ]);

        // Verify optimization
        $this->verifyOptimization($optimized, $image);

        return $optimized;
    }

    private function selectOptimizer(Image $image): ImageOptimizerInterface
    {
        return match($image->getFormat()) {
            'jpeg', 'jpg' => $this->optimizers['jpeg'],
            'png' => $this->optimizers['png'],
            'webp' => $this->optimizers['webp'],
            default => throw new OptimizationException('Unsupported format')
        };
    }

    private function verifyOptimization(OptimizedImage $optimized, Image $original): void
    {
        // Verify file size reduction
        if ($optimized->getSize() >= $original->getSize()) {
            throw new OptimizationException('Optimization failed to reduce file size');
        }

        // Verify quality
        if ($optimized->getQuality() < $this->config->get('image.min_quality')) {
            throw new OptimizationException('Optimization reduced quality below threshold');
        }
    }
}