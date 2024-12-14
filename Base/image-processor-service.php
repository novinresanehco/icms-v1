<?php

declare(strict_types=1);

namespace App\Services;

use Intervention\Image\ImageManager;
use Illuminate\Support\Facades\Storage;

class ImageProcessor
{
    private ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(['driver' => 'gd']);
    }

    public function createThumbnail(
        string $sourcePath,
        string $destinationPath,
        int $width,
        int $height
    ): void {
        $image = $this->manager->make($sourcePath);
        
        $image->fit($width, $height, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        });

        Storage::put($destinationPath, (string) $image->encode