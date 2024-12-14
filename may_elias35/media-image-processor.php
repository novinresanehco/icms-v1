<?php

namespace App\Core\Media\Processors;

use App\Core\Media\Contracts\MediaProcessorInterface;
use App\Core\Media\Models\Media;
use App\Core\Media\Config\ImageConfig;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;

class ImageProcessor implements MediaProcessorInterface
{
    protected ImageConfig $config;
    protected array $allowedMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp'
    ];

    public function __construct(ImageConfig $config)
    {
        $this->config = $config;
    }

    public function supports(Media $media): bool
    {
        return in_array($media->mime_type, $this->allowedMimeTypes);
    }

    public function process(Media $media): Media
    {
        $image = Image::make(Storage::path($media->path));
        
        // Process original image
        $this->optimizeOriginal($image, $media);
        
        // Generate thumbnails
        $thumbnails = $this->generateThumbnails($image, $media);
        
        // Extract and store metadata
        $metadata = array_merge(
            $this->extractMetadata($image),
            ['thumbnails' => $thumbnails]
        );

        // Update media record
        $media->update([
            'metadata' => $metadata,
            'status' => Media::STATUS_COMPLETED
        ]);

        return $media;
    }

    protected function optimizeOriginal($image, Media $media): void
    {
        // Resize if larger than max dimensions
        if ($image->width() > $this->config->maxWidth || 
            $image->height() > $this->config->maxHeight) {
            $image->resize($this->config->maxWidth, $this->config->maxHeight, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
        }

        // Optimize quality
        $image->save(
            Storage::path($media->path),
            $this->config->jpegQuality
        );
    }

    protected function generateThumbnails($image, Media $media): array
    {
        $thumbnails = [];

        foreach ($this->config->thumbnailSizes as $size => $dimensions) {
            $thumbnail = $image->resize($dimensions['width'], $dimensions['height'], function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });

            // Generate thumbnail path
            $thumbnailPath = $this->getThumbnailPath($media->path, $size);
            
            // Save thumbnail
            $thumbnail->save(
                Storage::path($thumbnailPath),
                $this->config->thumbnailQuality
            );

            $thumbnails[$size] = [
                'path' => $thumbnailPath,
                'width' => $thumbnail->width(),
                'height' => $thumbnail->height(),
                'size' => Storage::size($thumbnailPath)
            ];
        }

        return $thumbnails;
    }

    protected function extractMetadata($image): array
    {
        return [
            'dimensions' => [
                'width' => $image->width(),
                'height' => $image->height(),
            ],
            'exif' => $this->getExifData($image),
            'colors' => $this->extractDominantColors($image),
            'format' => $image->mime(),
            'processed_at' => now()
        ];
    }

    protected function getExifData($image): array
    {
        try {
            return $image->exif() ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    protected function extractDominantColors($image): array
    {
        $colors = [];
        $resized = $image->resize(150, 150);
        
        for ($x = 0; $x < 5; $x++) {
            for ($y = 0; $y < 5; $y++) {
                $colors[] = $resized->pickColor($x * 30, $y * 30, 'hex');
            }
        }

        return array_unique($colors);
    }

    protected function getThumbnailPath(string $originalPath, string $size): string
    {
        $pathInfo = pathinfo($originalPath);
        return $pathInfo['dirname'] . '/' . 
               $pathInfo['filename'] . '_' . 
               $size . '.' . 
               $pathInfo['extension'];
    }
}

namespace App\Core\Media\Config;

class ImageConfig
{
    public int $maxWidth = 2048;
    public int $maxHeight = 2048;
    public int $jpegQuality = 85;
    public int $thumbnailQuality = 80;
    
    public array $thumbnailSizes = [
        'small' => [
            'width' => 150,
            'height' => 150
        ],
        'medium' => [
            'width' => 400,
            'height' => 400
        ],
        'large' => [
            'width' => 800,
            'height' => 800
        ]
    ];

    public function __construct(array $config = [])
    {
        foreach ($config as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }
}

namespace App\Core\Media\Facades;

use App\Core\Media\Services\ImageTransformationService;
use Illuminate\Support\Facades\Facade;

class ImageTransform extends Facade
{
    protected static function getFacadeAccessor()
    {
        return ImageTransformationService::class;
    }
}

namespace App\Core\Media\Services;

class ImageTransformationService
{
    public function resize(Media $media, int $width, int $height, array $options = []): Media
    {
        $image = Image::make(Storage::path($media->path));
        
        $image->resize($width, $height, function ($constraint) use ($options) {
            if ($options['aspectRatio'] ?? true) {
                $constraint->aspectRatio();
            }
            if ($options['preventUpsize'] ?? true) {
                $constraint->upsize();
            }
        });

        $newPath = $this->getTransformedPath($media->path, "resize_{$width}x{$height}");
        
        $image->save(Storage::path($newPath));
        
        return $this->createTransformedMedia($media, $newPath, [
            'transformation' => 'resize',
            'params' => compact('width', 'height', 'options')
        ]);
    }

    public function crop(Media $media, int $width, int $height, array $options = []): Media
    {
        $image = Image::make(Storage::path($media->path));
        
        $image->fit($width, $height, function ($constraint) use ($options) {
            if ($options['upsize'] ?? false) {
                $constraint->upsize();
            }
        }, $options['position'] ?? 'center');

        $newPath = $this->getTransformedPath($media->path, "crop_{$width}x{$height}");
        
        $image->save(Storage::path($newPath));
        
        return $this->createTransformedMedia($media, $newPath, [
            'transformation' => 'crop',
            'params' => compact('width', 'height', 'options')
        ]);
    }

    protected function createTransformedMedia(Media $originalMedia, string $newPath, array $transformationData): Media
    {
        return Media::create([
            'filename' => basename($newPath),
            'mime_type' => $originalMedia->mime_type,
            'path' => $newPath,
            'size' => Storage::size($newPath),
            'metadata' => array_merge(
                $originalMedia->metadata ?? [],
                ['transformation' => $transformationData]
            ),
            'parent_id' => $originalMedia->id,
            'status' => Media::STATUS_COMPLETED
        ]);
    }

    protected function getTransformedPath(string $originalPath, string $suffix): string
    {
        $pathInfo = pathinfo($originalPath);
        return $pathInfo['dirname'] . '/' . 
               $pathInfo['filename'] . '_' . 
               $suffix . '.' . 
               $pathInfo['extension'];
    }
}
