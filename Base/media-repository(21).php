<?php

namespace App\Core\Repositories;

use App\Models\Media;
use App\Exceptions\MediaException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class MediaRepository
{
    protected $model;
    protected $disk = 'public';

    public function __construct(Media $model)
    {
        $this->model = $model;
    }

    public function storeFile(UploadedFile $file, array $metadata = []): Media
    {
        // Generate file hash to check for duplicates
        $hash = md5_file($file->path());
        
        // Check for existing file with same hash
        if ($existing = $this->findByHash($hash)) {
            return $existing;
        }

        $path = $this->generatePath($file->getClientOriginalName());
        
        // Store the file
        Storage::disk($this->disk)->putFileAs(
            dirname($path),
            $file,
            basename($path)
        );

        // Create media record
        return $this->model->create([
            'name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'hash' => $hash,
            'metadata' => json_encode(array_merge([
                'original_name' => $file->getClientOriginalName(),
            ], $metadata))
        ]);
    }

    public function storeFromUrl(string $url, array $metadata = []): Media
    {
        // Get file contents
        $contents = file_get_contents($url);
        if (!$contents) {
            throw new MediaException("Could not download file from URL: {$url}");
        }

        // Create temporary file
        $tempFile = tmpfile();
        fwrite($tempFile, $contents);
        $tempPath = stream_get_meta_data($tempFile)['uri'];

        // Create uploaded file instance
        $file = new UploadedFile(
            $tempPath,
            basename($url),
            mime_content_type($tempPath),
            null,
            true
        );

        try {
            return $this->storeFile($file, $metadata);
        } finally {
            fclose($tempFile);
        }
    }

    public function generateThumbnails(int $id, array $sizes): Media
    {
        $media = $this->model->findOrFail($id);
        $metadata = json_decode($media->metadata, true) ?? [];
        $metadata['thumbnails'] = [];

        foreach ($sizes as $name => [$width, $height]) {
            $path = "thumbnails/{$name}/" . basename($media->path);
            
            // Generate thumbnail
            $image = Image::make(Storage::disk($this->disk)->path($media->path));
            $image->fit($width, $height);
            
            // Store thumbnail
            Storage::disk($this->disk)->put(
                $path,
                $image->encode()
            );

            $metadata['thumbnails'][$name] = $path;
        }

        // Update metadata
        $media->metadata = json_encode($metadata);
        $media->save();

        return $media;
    }

    public function updateMetadata(int $id, array $metadata): Media
    {
        $media = $this->model->findOrFail($id);
        $existingMetadata = json_decode($media->metadata, true) ?? [];
        
        $media->metadata = json_encode(array_merge($existingMetadata, $metadata));
        $media->save();

        return $media;
    }

    public function moveToFolder(int $id, string $folder): Media
    {
        $media = $this->model->findOrFail($id);
        $newPath = trim($folder, '/') . '/' . basename($media->path);

        // Move the file
        Storage::disk($this->disk)->move($media->path, $newPath);

        // Move thumbnails if they exist
        $metadata = json_decode($media->metadata, true) ?? [];
        if (isset($metadata['thumbnails'])) {
            foreach ($metadata['thumbnails'] as $size => $thumbnailPath) {
                $newThumbnailPath = trim($folder, '/') . '/' . basename($thumbnailPath);
                Storage::disk($this->disk)->move($thumbnailPath, $newThumbnailPath);
                $metadata['thumbnails'][$size] = $newThumbnailPath;
            }
            $media->metadata = json_encode($metadata);
        }

        // Update path
        $media->path = $newPath;
        $media->save();

        return $media;
    }

    public function findByHash(string $hash): ?Media
    {
        return $this->model->where('hash', $hash)->first();
    }

    protected function generatePath(string $filename): string
    {
        return 'media/' . date('Y/m/d') . '/' . Str::random(40) . '.' . 
               pathinfo($filename, PATHINFO_EXTENSION);
    }
}
