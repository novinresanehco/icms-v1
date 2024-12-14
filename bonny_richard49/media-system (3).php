<?php

namespace App\Core\Template\Media;

class MediaProcessor implements MediaProcessorInterface
{
    private SecurityManager $security;
    private FileManager $files;
    private ImageProcessor $images;
    private CacheManager $cache;
    private Config $config;

    public function process(Media $media, ProcessingContext $context): ProcessedMedia
    {
        try {
            // Validate media
            $this->validateMedia($media);

            // Process based on type
            $processed = match($media->type) {
                'image' => $this->processImage($media, $context),
                'video' => $this->processVideo($media, $context),
                'document' => $this->processDocument($media, $context),
                default => throw new MediaException('Unsupported media type')
            };

            // Cache processed media
            $this->cacheProcessedMedia($processed);

            return $processed;

        } catch (\Exception $e) {
            $this->handleProcessingError($media, $e);
            throw $e;
        }
    }

    private function processImage(Media $media, ProcessingContext $context): ProcessedMedia
    {
        // Load image
        $image = $this->images->load($media->path);

        // Apply security filters
        $image = $this->security->sanitizeImage($image);

        // Process variants
        $variants = $this->processImageVariants($image, $context);

        return new ProcessedMedia($media, $variants);
    }

    private function processImageVariants(Image $image, ProcessingContext $context): array
    {
        $variants = [];
        foreach ($this->config->getImageVariants() as $variant => $specs) {
            $variants[$variant] = $this->images->processVariant($image, $specs);
        }
        return $variants;
    }

    private function cacheProcessedMedia(ProcessedMedia $media): void
    {
        $this->cache->put(
            $this->getCacheKey($media),
            $media,
            $this->config->get('media.cache.ttl')
        );
    }
}

class MediaGalleryRenderer implements GalleryRendererInterface
{
    private SecurityManager $security;
    private MediaProcessor $processor;
    private TemplateEngine $templates;
    private Config $config;

    public function render(array $media, RenderContext $context): string
    {
        try {
            // Validate gallery items
            $this->validateGalleryItems($media);

            // Process media items
            $processed = $this->processGalleryItems($media, $context);

            // Apply layout
            $layout = $this->determineLayout($processed, $context);

            // Render gallery
            return $this->templates->render('gallery', [
                'items' => $processed,
                'layout' => $layout,
                'context' => $context
            ]);

        } catch (\Exception $e) {
            $this->handleRenderError($media, $e);
            throw $e;
        }
    }

    private function processGalleryItems(array $media, RenderContext $context): array
    {
        return array_map(
            fn($item) => $this->processor->process($item, $context),
            $media
        );
    }

    private function determineLayout(array $items, RenderContext $context): GalleryLayout
    {
        return new GalleryLayout([
            'type' => $context->getLayoutType(),
            'columns' => $this->calculateColumns($items),
            'spacing' => $this->config->get('gallery.spacing'),
            'aspectRatio' => $this->config->get('gallery.aspect_ratio')
        ]);
    }
}

class ImageProcessor implements ImageProcessorInterface
{
    private SecurityManager $security;
    private ImageOptimizer $optimizer;
    private MetadataManager $metadata;
    private Config $config;

    public function process(Image $image, ProcessingContext $context): ProcessedImage
    {
        try {
            // Validate image
            $this->validateImage($image);

            // Strip metadata
            $this->metadata->strip($image);

            // Apply security filters
            $this->security->sanitizeImage($image);

            // Optimize image
            $this->optimizer->optimize($image, [
                'quality' => $this->config->get('image.quality'),
                'format' => $this->config->get('image.format'),
                'strip' => true
            ]);

            return new ProcessedImage($image);

        } catch (\Exception $e) {
            $this->handleProcessingError($image, $e);
            throw $e;
        }
    }

    private function validateImage(Image $image): void
    {
        if (!$this->security->validateImage($image)) {
            throw new SecurityException('Image validation failed');
        }

        $limits = $this->config->get('image.limits');
        if ($image->getSize() > $limits['max_size']) {
            throw new ValidationException('Image exceeds size limit');
        }
    }
}