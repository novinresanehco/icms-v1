<?php

namespace App\Core\Media\Services\Processors;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;
use FFMpeg\FFMpeg;

class ImageProcessor implements MediaProcessorInterface
{
    public function process(UploadedFile $file, array $options = []): array
    {
        $fileName = $this->generateFileName($file);
        $path = $this->generatePath('images', $fileName);
        
        $image = Image::make($file);
        $metadata = $this->extractMetadata($image);
        
        // Store original
        $image->save(storage_path("app/public/{$path}"));
        
        // Generate variants
        $variants = [];
        
        if (!($options['skip_variants'] ?? false)) {
            $variants = $this->generateVariants($image, $fileName);
        }

        return [
            'file_name' => $fileName,
            'path' => $path,
            'metadata' => $metadata,
            'variants' => $variants
        ];
    }

    protected function generateVariants($image, string $fileName): array
    {
        $variants = [];
        $sizes = config('media.image_sizes', []);

        foreach ($sizes as $size => $dimensions) {
            $variant = $image->fit($dimensions['width'], $dimensions['height']);
            $variantFileName = $this->generateVariantFileName($fileName, $size);
            $variantPath = $this->generatePath('images/variants', $variantFileName);
            
            $variant->save(storage_path("app/public/{$variantPath}"));
            
            $variants[$size] = [
                'file_name' => $variantFileName,
                'path' => $variantPath,
                'size' => $variant->filesize(),
                'metadata' => [
                    'width' => $dimensions['width'],
                    'height' => $dimensions['height']
                ]
            ];
        }

        return $variants;
    }

    protected function extractMetadata($image): array
    {
        return [
            'width' => $image->width(),
            'height' => $image->height(),
            'format' => $image->mime(),
            'exif' => $image->exif() ?? []
        ];
    }
}

class VideoProcessor implements MediaProcessorInterface
{
    private $ffmpeg;

    public function __construct()
    {
        $this->ffmpeg = FFMpeg::create([
            'ffmpeg.binaries' => config('media.ffmpeg_path'),
            'ffprobe.binaries' => config('media.ffprobe_path')
        ]);
    }

    public function process(UploadedFile $file, array $options = []): array
    {
        $fileName = $this->generateFileName($file);
        $path = $this->generatePath('videos', $fileName);
        
        // Store original
        $file->storeAs('public/' . dirname($path), $fileName);
        
        $video = $this->ffmpeg->open($file->getPathname());
        $metadata = $this->extractMetadata($video);
        
        // Generate thumbnail
        $variants = [];
        if (!($options['skip_thumbnail'] ?? false)) {
            $variants['thumbnail'] = $this->generateThumbnail($video, $fileName);
        }

        return [
            'file_name' => $fileName,
            'path' => $path,
            'metadata' => $metadata,
            'variants' => $variants
        ];
    }

    protected function extractMetadata($video): array
    {
        $streams = $video->getStreams();
        
        return [
            'duration' => $streams->first()->get('duration'),
            'width' => $streams->first()->get('width'),
            'height' => $streams->first()->get('height'),
            'format' => $streams->first()->get('codec_name')
        ];
    }

    protected function generateThumbnail($video, string $fileName): array
    {
        $thumbnailFileName = Str::beforeLast($fileName, '.') . '_thumb.jpg';
        $thumbnailPath = $this->generatePath('videos/thumbnails', $thumbnailFileName);
        
        $frame = $video->frame(\FFMpeg\Coordinate\TimeCode::fromSeconds(0));
        $frame->save(storage_path("app/public/{$thumbnailPath}"));
        
        return [
            'file_name' => $thumbnailFileName,
            'path' => $thumbnailPath,
            'mime_type' => 'image/jpeg'
        ];
    }
}

class DocumentProcessor implements MediaProcessorInterface
{
    public function process(UploadedFile $file, array $options = []): array
    {
        $fileName = $this->generateFileName($file);
        $path = $this->generatePath('documents', $fileName);
        
        // Store original
        $file->storeAs('public/' . dirname($path), $fileName);
        
        return [
            'file_name' => $fileName,
            'path' => $path,
            'metadata' => $this->extractMetadata($file)
        ];
    }

    protected function extractMetadata(UploadedFile $file): array
    {
        return [
            'extension' => $file->getClientOriginalExtension(),
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType()
        ];
    }
}

trait MediaProcessorTrait
{
    protected function generateFileName(UploadedFile $file): string
    {
        return Str::random(40) . '.' . $file->getClientOriginalExtension();
    }

    protected function generateVariantFileName(string $fileName, string $variant): string
    {
        return Str::beforeLast($fileName, '.') . "_{$variant}." . Str::afterLast($fileName, '.');
    }

    protected function generatePath(string $type, string $fileName): string
    {
        return $type . '/' . date('Y/m/d') . '/' . $fileName;
    }
}
