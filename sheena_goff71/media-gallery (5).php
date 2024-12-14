<?php

namespace App\Core\Media;

class MediaGalleryProcessor
{
    private SecurityValidator $validator;
    private ImageProcessor $imageProcessor;
    private CacheManager $cache;

    public function __construct(
        SecurityValidator $validator,
        ImageProcessor $imageProcessor,
        CacheManager $cache
    ) {
        $this->validator = $validator;
        $this->imageProcessor = $imageProcessor;
        $this->cache = $cache;
    }

    public function processGallery(array $media, array $options = []): array
    {
        $this->validator->validateMediaInput($media);
        return array_map(
            fn($item) => $this->processMediaItem($item, $options),
            $media
        );
    }

    private function processMediaItem(array $item, array $options): array
    {
        $cacheKey = $this->generateCacheKey($item, $options);
        
        return $this->cache->remember($cacheKey, fn() => [
            'src' => $this->imageProcessor->process($item['src'], $options),
            'thumbnail' => $this->generateThumbnail($item['src'], $options),
            'alt' => $this->validator->sanitizeString($item['alt'] ?? ''),
            'title' => $this->validator->sanitizeString($item['title'] ?? ''),
            'meta' => $this->processMetadata($item['meta'] ?? [])
        ]);
    }

    private function generateThumbnail(string $src, array $options): string
    {
        return $this->imageProcessor->createThumbnail($src, [
            'width' => $options['thumbnailWidth'] ?? 200,
            'height' => $options['thumbnailHeight'] ?? 200,
            'format' => $options['thumbnailFormat'] ?? 'webp'
        ]);
    }

    private function processMetadata(array $meta): array
    {
        return $this->validator->sanitizeMetadata($meta);
    }

    private function generateCacheKey(array $item, array $options): string
    {
        return sprintf(
            'media:gallery:%s:%s',
            md5(serialize($item)),
            md5(serialize($options))
        );
    }
}

class GalleryRenderer
{
    private TemplateEngine $templateEngine;
    private MediaGalleryProcessor $processor;

    public function __construct(
        TemplateEngine $templateEngine,
        MediaGalleryProcessor $processor
    ) {
        $this->templateEngine = $templateEngine;
        $this->processor = $processor;
    }

    public function render(array $media, array $options = []): string
    {
        $processedMedia = $this->processor->processGallery($media, $options);
        
        return $this->templateEngine->render('gallery', [
            'media' => $processedMedia,
            'layout' => $options['layout'] ?? 'grid',
            'columns' => $options['columns'] ?? 3
        ]);
    }
}

class ImageProcessor
{
    private array $allowedFormats = ['jpg', 'png', 'webp'];
    private array $allowedOperations = ['resize', 'compress', 'convert'];

    public function process(string $src, array $options): string
    {
        $this->validateSource($src);
        $this->validateOptions($options);

        $image = $this->loadImage($src);
        
        foreach ($options['operations'] ?? [] as $operation) {
            $image = $this->applyOperation($image, $operation);
        }

        return $this->saveImage($image, $options['output'] ?? []);
    }

    public function createThumbnail(string $src, array $options): string
    {
        return $this->process($src, [
            'operations' => [
                ['type' => 'resize', 'dimensions' => [
                    $options['width'],
                    $options['height']
                ]],
                ['type' => 'compress', 'quality' => 85]
            ],
            'output' => [
                'format' => $options['format']
            ]
        ]);
    }

    private function validateSource(string $src): void
    {
        if (!filter_var($src, FILTER_VALIDATE_URL)) {
            throw new MediaProcessingException('Invalid source URL');
        }
    }

    private function validateOptions(array $options): void
    {
        foreach ($options['operations'] ?? [] as $operation) {
            if (!in_array($operation['type'], $this->allowedOperations)) {
                throw new MediaProcessingException('Invalid operation type');
            }
        }
    }

    private function loadImage(string $src)
    {
        // Image loading implementation
        return null;
    }

    private function applyOperation($image, array $operation)
    {
        // Operation application implementation
        return $image;
    }

    private function saveImage($image, array $options): string
    {
        // Image saving implementation
        return '';
    }
}
