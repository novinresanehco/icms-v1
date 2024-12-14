<?php

namespace App\Core\Processing;

use Illuminate\Http\UploadedFile;
use App\Core\Exceptions\MediaProcessingException;
use Intervention\Image\Facades\Image;

class MediaProcessor
{
    protected array $allowedMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
        'video/mp4'
    ];

    protected array $thumbnailSizes = [
        'small' => [150, 150],
        'medium' => [300, 300],
        'large' => [600, 600]
    ];

    public function process(UploadedFile $file): ProcessedMedia
    {
        if (!in_array($file->getMimeType(), $this->allowedMimeTypes)) {
            throw new MediaProcessingException("Unsupported file type: {$file->getMimeType()}");
        }

        try {
            $processedFile = new ProcessedMedia($file);

            if ($this->isImage($file)) {
                $processedFile = $this->processImage($processedFile);
            } elseif ($this->isVideo($file)) {
                $processedFile = $this->processVideo($processedFile);
            }

            return $processedFile;

        } catch (\Exception $e) {
            throw new MediaProcessingException("Failed to process media: {$e->getMessage()}", 0, $e);
        }
    }

    protected function processImage(ProcessedMedia $media): ProcessedMedia
    {
        $image = Image::make($media->getPathname());

        // Auto-orient image based on EXIF data
        $image->orientate();

        // Generate thumbnails
        foreach ($this->thumbnailSizes as $size => [$width, $height]) {
            $thumbnail = clone $image;
            $thumbnail->fit($width, $height, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });

            $thumbnailPath = $this->generateThumbnailPath($media->getPathname(), $size);
            $thumbnail->save($thumbnailPath);
            $media->addThumbnail($size, $thumbnailPath);
        }

        // Optimize original image if needed
        if ($image->filesize() > 1024 * 1024) { // 1MB
            $image->save($media->getPathname(), 85);
        }

        // Set image dimensions
        $media->setDimensions($image->width(), $image->height());

        return $media;
    }

    protected function processVideo(ProcessedMedia $media): ProcessedMedia
    {
        // Video processing logic would go here
        // This could include generating thumbnails, transcoding, etc.
        return $media;
    }

    public function regenerateThumbnails(ProcessedMedia $media): ProcessedMedia
    {
        if (!$this->isImage($media)) {
            throw new MediaProcessingException("Can only regenerate thumbnails for images");
        }

        // Clear existing thumbnails
        $media->clearThumbnails();

        // Regenerate thumbnails
        return $this->processImage($media);
    }

    public function optimize(ProcessedMedia $media): ProcessedMedia
    {
        if ($this->isImage($media)) {
            $image = Image::make($media->getPathname());
            
            // Apply optimization based on image type
            switch ($media->getMimeType()) {
                case 'image/jpeg':
                    $image->save($media->getPathname(), 85);
                    break;
                case 'image/png':
                    $image->save($media->getPathname(), 8);
                    break;
                case 'image/webp':
                    $image->save($media->getPathname(), 