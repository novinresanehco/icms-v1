<?php

namespace App\Core\Media;

use App\Core\Security\SecurityManager;
use Intervention\Image\ImageManager;

class ImageProcessor
{
    private SecurityManager $security;
    private ImageManager $imageManager;
    
    private const MAX_DIMENSION = 4096;
    private const QUALITY = 85;
    private const ALLOWED_FORMATS = ['jpg', 'png', 'gif'];

    public function process(array $file): array
    {
        // Load image
        $image = $this->imageManager->make($file['content']);
        
        // Validate dimensions
        $this->validateDimensions($image);
        
        // Strip metadata
        $this->stripMetadata($image);
        
        // Optimize
        $optimized = $this->optimizeImage($image, $file['mime_type']);
        
        return [
            'content' => $optimized,
            'mime_type' => $file['mime_type'],
            'size' => strlen($optimized)
        ];
    }

    private function validateDimensions($image): void
    {
        if ($image->width() > self::MAX_DIMENSION || 
            $image->height() > self::MAX_DIMENSION) {
            throw new MediaValidationException('Image dimensions exceed maximum allowed');
        }
    }

    private function stripMetadata($image): void
    {
        // Remove EXIF
        $image->removeExif();
        
        // Strip other metadata
        $image->strip();
    }

    private function optimizeImage($image, string $mimeType): string
    {
        // Resize if needed
        if ($image->width() > 2048 || $image->height() > 2048) {
            $image->resize(2048, 2048, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
        }

        // Format-specific optimization
        switch ($mimeType) {
            case 'image/jpeg':
                return $image->encode('jpg', self::QUALITY)->encoded;
            
            case 'image/png':
                return $image->encode('png', 9)->encoded;
            
            case 'image/gif':
                return $image->encode('gif')->encoded;
            
            default:
                throw new MediaValidationException('Unsupported image format');
        }
    }
}
