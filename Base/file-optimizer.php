<?php

namespace App\Core\Services;

use App\Core\Services\Contracts\FileOptimizerInterface;
use Intervention\Image\Facades\Image;
use Spatie\ImageOptimizer\OptimizerChainFactory;

class FileOptimizer implements FileOptimizerInterface
{
    protected $optimizerChain;

    public function __construct()
    {
        $this->optimizerChain = OptimizerChainFactory::create();
    }

    public function optimize(string $path): void
    {
        if ($this->isImage($path)) {
            $this->optimizeImage($path);
        }
        
        $this->optimizerChain->optimize($path);
    }

    protected function optimizeImage(string $path): void
    {
        $image = Image::make($path);
        
        // Auto-orient image based on EXIF data
        $image->orientate();
        
        // Strip EXIF data to reduce size
        $image->strip();
        
        // Optimize quality
        $image->save($path, config('media.image_quality', 85));
    }

    protected function isImage(string $path): bool
    {
        $mime = mime_content_type($path);
        return str_starts_with($mime, 'image/');
    }
}
