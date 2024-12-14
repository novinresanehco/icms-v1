<?php

namespace App\Core\Media;

use Illuminate\Support\Facades\{Storage, Cache, DB};
use App\Core\Security\SecurityManager;
use App\Core\Services\{ValidationService, ImageService, HashService};
use App\Core\Exceptions\{MediaException, ValidationException, SecurityException};

class MediaManager implements MediaManagementInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private ImageService $image;
    private HashService $hash;

    private const ALLOWED_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 
        'application/pdf', 'text/plain'
    ];
    private const MAX_FILE_SIZE = 10485760; // 10MB
    private const CACHE_TTL = 3600;
    private const CHUNK_SIZE = 1048576; // 1MB

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        ImageService $image,
        HashService $hash
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->image = $image;
        $this->hash = $hash;
    }

    public function store(array $data): MediaFile
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeStore($data),
            ['action' => 'media.store']
        );
    }

    protected function executeStore(array $data): MediaFile
    {
        $file = $data['file'];
        
        $this->validateFile($file);
        $hash = $this->hash->generateFileHash($file);

        DB::beginTransaction();
        try {
            // Check for duplicates
            $existing = $this->findByHash($hash);
            if ($existing) {
                return $existing;
            }

            // Store file securely
            $path = $this->storeFile($file, $hash);

            // Process image if applicable
            $metadata = $this->processFile($file, $path);

            // Create database record
            $media = MediaFile::create([
                'path' => $path,
                'filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'hash' => $hash,
                'metadata' => $metadata,
                'created_by' => auth()->id()
            ]);

            DB::commit();

            return $media;

        } catch (\Exception $e) {
            DB::rollBack();
            Storage::delete($path ?? null);
            
            throw new MediaException('Failed to store media: ' . $e->getMessage());
        }
    }

    public function retrieve(int $id): MediaFile
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeRetrieve($id),
            ['action' => 'media.retrieve', 'id' => $id]
        );
    }

    protected function executeRetrieve(int $id): MediaFile
    {
        $cacheKey = "media.{$id}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function() use ($id) {
            $media = MediaFile::findOrFail($id);
            
            if (!Storage::exists($media->path)) {
                throw new MediaException('Media file not found');
            }

            return $media;
        });
    }

    public function getStream(MediaFile $media): \Generator
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeGetStream($media),
            ['action' => 'media.stream', 'id' => $media->id]
        );
    }

    protected function executeGetStream(MediaFile $media): \Generator
    {
        $path = storage_path('app/' . $media->path);
        
        if (!file_exists($path)) {
            throw new MediaException('Media file not found');
        }

        $handle = fopen($path, 'rb');
        
        while (!feof($handle)) {
            yield fread($handle, self::CHUNK_SIZE);
        }
        
        fclose($handle);
    }

    public function delete(int $id): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeDelete($id),
            ['action' => 'media.delete', 'id' => $id]
        );
    }

    protected function executeDelete(int $id): bool
    {
        DB::beginTransaction();
        try {
            $media = MediaFile::findOrFail($id);
            
            // Delete file
            if (Storage::exists($media->path)) {
                Storage::delete($media->path);
            }

            // Delete thumbnails if they exist
            if (!empty($media->metadata['thumbnails'])) {
                foreach ($media->metadata['thumbnails'] as $thumbnail) {
                    Storage::delete($thumbnail);
                }
            }

            // Delete record
            $media->delete();
            
            // Clear cache
            Cache::forget("media.{$id}");

            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new MediaException('Failed to delete media: ' . $e->getMessage());
        }
    }

    protected function validateFile($file): void
    {
        if (!$file->isValid()) {
            throw new ValidationException('Invalid file upload');
        }

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new ValidationException('File size exceeds limit');
        }

        if (!in_array($file->getMimeType(), self::ALLOWED_TYPES)) {
            throw new ValidationException('File type not allowed');
        }

        // Scan file for malware
        $this->scanFile($file);
    }

    protected function scanFile($file): void
    {
        // Implementation depends on antivirus solution
        // Must throw SecurityException if threat detected
    }

    protected function storeFile($file, string $hash): string
    {
        $extension = $file->getClientOriginalExtension();
        $path = "media/{$hash}.{$extension}";

        Storage::put(
            $path,
            file_get_contents($file->getRealPath())
        );

        return $path;
    }

    protected function processFile($file, string $path): array
    {
        $metadata = [
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize()
        ];

        if (strpos($file->getMimeType(), 'image/') === 0) {
            $metadata = array_merge(
                $metadata,
                $this->processImage($file, $path)
            );
        }

        return $metadata;
    }

    protected function processImage($file, string $path): array
    {
        $metadata = [
            'dimensions' => $this->image->getDimensions($file),
            'thumbnails' => []
        ];

        // Generate thumbnails
        $sizes = ['small' => 150, 'medium' => 300, 'large' => 600];
        
        foreach ($sizes as $size => $dimension) {
            $thumbnailPath = "media/thumbnails/{$size}/" . basename($path);
            
            $this->image->createThumbnail(
                $file,
                $thumbnailPath,
                $dimension
            );
            
            $metadata['thumbnails'][$size] = $thumbnailPath;
        }

        return $metadata;
    }

    protected function findByHash(string $hash): ?MediaFile
    {
        return MediaFile::where('hash', $hash)->first();
    }
}
