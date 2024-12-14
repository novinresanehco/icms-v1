<?php

namespace App\Core\Storage;

use App\Core\Processing\ProcessedMedia;
use App\Core\Exceptions\MediaStorageException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaStorage
{
    public function __construct(
        protected string $disk = 'public',
        protected string $basePath = 'media'
    ) {}

    public function store(ProcessedMedia $media): string
    {
        try {
            $path = $this->generatePath($media);
            $stream = fopen($media->getPathname(), 'r');
            
            Storage::disk($this->disk)->put($path, $stream);
            
            if (is_resource($stream)) {
                fclose($stream);
            }

            foreach ($media->getThumbnails() as $size => $thumbnailPath) {
                $thumbnailStoragePath = $this->generateThumbnailPath($path, $size);
                $thumbnailStream = fopen($thumbnailPath, 'r');
                
                Storage::disk($this->disk)->put($thumbnailStoragePath, $thumbnailStream);
                
                if (is_resource($thumbnailStream)) {
                    fclose($thumbnailStream);
                }
            }

            return $path;

        } catch (\Exception $e) {
            throw new MediaStorageException("Failed to store media: {$e->getMessage()}", 0, $e);
        }
    }

    public function retrieve(string $path): ProcessedMedia
    {
        try {
            if (!Storage::disk($this->disk)->exists($path)) {
                throw new MediaStorageException("Media file not found at path: {$path}");
            }

            $tempPath = tempnam(sys_get_temp_dir(), 'media_');
            file_put_contents($tempPath, Storage::disk($this->disk)->get($path));

            return new ProcessedMedia(new \Illuminate\Http\UploadedFile(
                $tempPath,
                basename($path),
                Storage::disk($this->disk)->mimeType($path),
                null,
                true
            ));

        } catch (\Exception $e) {
            throw new MediaStorageException("Failed to retrieve media: {$e->getMessage()}", 0, $e);
        }
    }

    public function delete(string $path): bool
    {
        try {
            $disk = Storage::disk($this->disk);
            
            if (!$disk->exists($path)) {
                throw new MediaStorageException("Media file not found at path: {$path}");
            }

            // Delete original file
            $disk->delete($path);

            // Delete thumbnails if they exist
            $pathInfo = pathinfo($path);
            $pattern = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_*.' . $pathInfo['extension'];
            
            foreach ($disk->files($pathInfo['dirname']) as $file) {
                if (fnmatch($pattern, $file)) {
                    $disk->delete($file);
                }
            }

            return true;

        } catch (\Exception $e) {
            throw new MediaStorageException("Failed to delete media: {$e->getMessage()}", 0, $e);
        }
    }

    protected function generatePath(ProcessedMedia $media): string
    {
        $hash = Str::random(40);
        $extension = pathinfo($media->getPathname(), PATHINFO_EXTENSION);
        
        return sprintf(
            '%s/%s/%s/%s.%s',
            $this->basePath,
            substr($hash, 0, 2),
            substr($hash, 2, 2),
            $hash,
            $extension
        );
    }

    protected function generateThumbnailPath(string $originalPath, string $size): string
    {
        $pathInfo = pathinfo($originalPath);
        return $pathInfo['dirname'] . '/' . $pathInfo['filename'] . "_{$size}." . $pathInfo['extension'];
    }

    public function cleanup(?ProcessedMedia $media): void
    {
        if ($media) {
            @unlink($media->getPathname());
            foreach ($media->getThumbnails() as $path) {
                @unlink($path);
            }
        }
    }
}
