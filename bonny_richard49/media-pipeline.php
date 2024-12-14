<?php

namespace App\Core\Media\Processors;

use Illuminate\Http\UploadedFile;
use App\Core\Media\Processors\ProcessedMedia;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;

class MediaProcessingPipeline
{
    protected array $processors = [];

    public function addProcessor(MediaProcessorInterface $processor): self
    {
        $this->processors[] = $processor;
        return $this;
    }

    public function process(UploadedFile $file): ProcessedMedia
    {
        $processedMedia = new ProcessedMedia($file);

        foreach ($this->processors as $processor) {
            $processedMedia = $processor->process($processedMedia);
        }

        return $processedMedia;
    }
}

namespace App\Core\Media\Processors;

use Illuminate\Http\UploadedFile;

class ProcessedMedia
{
    protected string $path;
    protected string $disk;
    protected array $metadata = [];
    protected UploadedFile $originalFile;

    public function __construct(UploadedFile $file)
    {
        $this->originalFile = $file;
    }

    public function setPath(string $path): self
    {
        $this->path = $path;
        return $this;
    }

    public function setDisk(string $disk): self
    {
        $this->disk = $disk;
        return $this;
    }

    public function addMetadata(string $key, $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getDisk(): string
    {
        return $this->disk;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getOriginalFile(): UploadedFile
    {
        return $this->originalFile;
    }
}

namespace App\Core\Media\Processors;

interface MediaProcessorInterface
{
    public function process(ProcessedMedia $media): ProcessedMedia;
}

namespace App\Core\Media\Processors;

class ImageOptimizer implements MediaProcessorInterface
{
    public function process(ProcessedMedia $media): ProcessedMedia
    {
        if (!str_starts_with($media->getOriginalFile()->getMimeType(), 'image/')) {
            return $media;
        }

        $image = Image::make($media->getOriginalFile());

        // Optimize image
        $image->encode(null, 85); // 85% quality

        // Save optimized image
        $path = 'media/' . uniqid() . '.' . $media->getOriginalFile()->extension();
        Storage::disk(config('media.disk'))->put($path, $image->stream());

        return $media
            ->setPath($path)
            ->setDisk(config('media.disk'))
            ->addMetadata('optimized', true)
            ->addMetadata('dimensions', [
                'width' => $image->width(),
                'height' => $image->height()
            ]);
    }
}

namespace App\Core\Media\Processors;

class ThumbnailGenerator implements MediaProcessorInterface
{
    protected array $sizes = [
        'small' => [150, 150],
        'medium' => [300, 300],
        'large' => [600, 600]
    ];

    public function process(ProcessedMedia $media): ProcessedMedia
    {
        if (!str_starts_with($media->getOriginalFile()->getMimeType(), 'image/')) {
            return $media;
        }

        $thumbnails = [];
        $image = Image::make($media->getOriginalFile());

        