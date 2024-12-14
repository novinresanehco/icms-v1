<?php

namespace App\Core\Template\Media;

class MediaGalleryManager implements GalleryInterface 
{
    private SecurityManager $security;
    private MediaProcessor $processor;
    private CacheManager $cache;
    
    public function __construct(
        SecurityManager $security,
        MediaProcessor $processor,
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->processor = $processor;
        $this->cache = $cache;
    }

    public function render(array $media, array $options = []): string 
    {
        return DB::transaction(function() use ($media, $options) {
            $this->security->validateMediaAccess($media);
            
            return $this->cache->remember(
                $this->getCacheKey($media, $options),
                function() use ($media, $options) {
                    $processedMedia = $this->processor->batchProcess($media, $options);
                    return view('components.gallery', [
                        'media' => $processedMedia,
                        'layout' => $this->getOptimalLayout($processedMedia),
                        'options' => $this->validateOptions($options)
                    ])->render();
                }
            );
        });
    }

    public function processMedia(MediaItem $item, array $options = []): ProcessedMedia 
    {
        $this->security->validateMediaProcessing($item);
        return $this->processor->process($item, $options);
    }

    private function getOptimalLayout(array $media): array 
    {
        $layoutEngine = new GalleryLayoutEngine();
        return $layoutEngine->calculateOptimalLayout($media);
    }

    private function validateOptions(array $options): array 
    {
        $validator = new MediaOptionsValidator();
        return $validator->validate($options);
    }

    private function getCacheKey(array $media, array $options): string 
    {
        return 'gallery:' . md5(serialize($media) . serialize($options));
    }
}

class GalleryLayoutEngine 
{
    public function calculateOptimalLayout(array $media): array 
    {
        $columns = $this->calculateOptimalColumns(count($media));
        return [
            'columns' => $columns,
            'rows' => ceil(count($media) / $columns),
            'spacing' => $this->calculateOptimalSpacing($media),
            'sizes' => $this->calculateMediaSizes($media, $columns)
        ];
    }

    private function calculateOptimalColumns(int $count): int 
    {
        return min(max(floor(sqrt($count)), 1), 4);
    }

    private function calculateOptimalSpacing(array $media): int 
    {
        return 16; // Base spacing in pixels
    }

    private function calculateMediaSizes(array $media, int $columns): array 
    {
        $sizes = [];
        foreach ($media as $item) {
            $sizes[] = $this->calculateMediaSize($item, $columns);
        }
        return $sizes;
    }

    private function calculateMediaSize(MediaItem $item, int $columns): array 
    {
        $aspectRatio = $item->getWidth() / $item->getHeight();
        $width = floor(100 / $columns);
        return [
            'width' => $width . '%',
            'height' => floor($width / $aspectRatio) . 'px'
        ];
    }
}

class MediaOptionsValidator 
{
    private const ALLOWED_OPTIONS = [
        'layout_type',
        'max_columns',
        'spacing',
        'thumbnail_size',
        'lazy_load',
        'preload'
    ];

    public function validate(array $options): array 
    {
        $validated = [];
        foreach (self::ALLOWED_OPTIONS as $option) {
            if (isset($options[$option])) {
                $validated[$option] = $this->validateOption($option, $options[$option]);
            }
        }
        return $validated;
    }

    private function validateOption(string $option, $value)
    {
        return match ($option) {
            'layout_type' => $this->validateLayoutType($value),
            'max_columns' => $this->validateMaxColumns($value),
            'spacing' => $this->validateSpacing($value),
            'thumbnail_size' => $this->validateThumbnailSize($value),
            'lazy_load' => (bool) $value,
            'preload' => (bool) $value,
            default => null
        };
    }

    private function validateLayoutType(string $type): string 
    {
        return in_array($type, ['grid', 'masonry', 'carousel']) ? $type : 'grid';
    }

    private function validateMaxColumns(int $columns): int 
    {
        return min(max($columns, 1), 6);
    }

    private function validateSpacing(int $spacing): int 
    {
        return min(max($spacing, 0), 32);
    }

    private function validateThumbnailSize(array $size): array 
    {
        return [
            'width' => min(max($size['width'] ?? 200, 50), 800),
            'height' => min(max($size['height'] ?? 200, 50), 800)
        ];
    }
}

interface GalleryInterface 
{
    public function render(array $media, array $options = []): string;
    public function processMedia(MediaItem $item, array $options = []): ProcessedMedia;
}
