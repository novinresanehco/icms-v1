<?php

namespace App\Core\Processing;

class MediaProcessor
{
    protected array $optimizationConfig = [
        'image/jpeg' => ['quality' => 85],
        'image/png' => ['quality' => 8],
        'image/webp' => ['quality' => 80],
        'image/gif' => ['preserve_animation' => true],
    ];

    public function optimize(ProcessedMedia $media): ProcessedMedia
    {
        if ($this->isImage($media)) {
            $image = Image::make($media->getPathname());
            $config = $this->optimizationConfig[$media->getMimeType()] ?? [];
            
            $image->save($media->getPathname(), $config['quality'] ?? null);
            
            if (isset($config['preserve_animation']) && $config['preserve_animation']) {
                $this->optimizeGif($media->getPathname());
            }
        }

        return $media;
    }

    protected function optimizeGif(string $path): void
    {
        if (!function_exists('exec')) {
            return;
        }
        
        exec("gifsicle -O3 --colors 256 $path -o $path");
    }

    protected function generateThumbnailPath(string $originalPath, string $size): string
    {
        $info = pathinfo($originalPath);
        return $info['dirname'] . '/' . $info['filename'] . "_{$size}." . $info['extension'];
    }

    protected function isImage($file): bool
    {
        return str_starts_with($file->getMimeType(), 'image/');
    }

    protected function isVideo($file): bool
    {
        return str_starts_with($file->getMimeType(), 'video/');
    }

    public function getMetadata(ProcessedMedia $media): array
    {
        $metadata = [
            'filesize' => $media->getSize(),
            'mime_type' => $media->getMimeType(),
            'created_at' => now(),
            'processed_at' => now(),
        ];

        if ($this->isImage($media)) {
            $image = Image::make($media->getPathname());
            $metadata = array_merge($metadata, [
                'width' => $image->width(),
                'height' => $image->height(),
                'orientation' => $image->exif()['Orientation'] ?? null,
                'color_space' => $image->exif()['ColorSpace'] ?? null,
            ]);
        }

        return $metadata;
    }
}

class ProcessedMedia
{
    protected array $thumbnails = [];
    protected array $dimensions = [];
    protected array $metadata = [];

    public function __construct(protected UploadedFile $file)
    {
    }

    public function getPathname(): string
    {
        return $this->file->getPathname();
    }

    public function getMimeType(): string
    {
        return $this->file->getMimeType();
    }

    public function getSize(): int
    {
        return $this->file->getSize();
    }

    public function addThumbnail(string $size, string $path): void
    {
        $this->thumbnails[$size] = $path;
    }

    public function getThumbnails(): array
    {
        return $this->thumbnails;
    }

    public function clearThumbnails(): void
    {
        foreach ($this->thumbnails as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        $this->thumbnails = [];
    }

    public function setDimensions(int $width, int $height): void
    {
        $this->dimensions = compact('width', 'height');
    }

    public function getDimensions(): array
    {
        return $this->dimensions;
    }

    public function setMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
