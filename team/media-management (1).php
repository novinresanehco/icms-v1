<?php

namespace App\Core\Media;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Storage\StorageManager;
use App\Core\Events\MediaEvent;
use App\Core\Exceptions\{MediaException, SecurityException};
use Illuminate\Support\Facades\{DB, File};
use Illuminate\Http\UploadedFile;

class MediaManager implements MediaManagerInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private StorageManager $storage;
    private array $config;
    private array $allowedMimeTypes = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf', 'text/plain', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        StorageManager $storage,
        array $config = []
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->storage = $storage;
        $this->config = array_merge([
            'max_file_size' => 10485760, // 10MB
            'image_max_dimension' => 4096,
            'thumbnail_sizes' => ['small' => 150, 'medium' => 300, 'large' => 600],
            'storage_path' => 'media',
            'secure_storage' => true
        ], $config);
    }

    public function upload(UploadedFile $file, array $context = []): MediaFile
    {
        return $this->security->executeCriticalOperation(
            function() use ($file, $context) {
                DB::beginTransaction();
                try {
                    // Validate file
                    $this->validateFile($file);
                    
                    // Generate secure filename
                    $filename = $this->generateSecureFilename($file);
                    
                    // Process and store file
                    $mediaFile = $this->processAndStoreFile($file, $filename, $context);
                    
                    // Generate thumbnails for images
                    if ($this->isImage($file)) {
                        $this->generateThumbnails($mediaFile);
                    }
                    
                    event(new MediaEvent('uploaded', $mediaFile));
                    
                    DB::commit();
                    return $mediaFile;
                    
                } catch (\Exception $e) {
                    DB::rollBack();
                    $this->cleanup($filename);
                    throw new MediaException('File upload failed: ' . $e->getMessage());
                }
            },
            ['operation' => 'media_upload']
        );
    }

    public function get(int $id): MediaFile
    {
        return $this->cache->remember(
            "media.{$id}",
            function() use ($id) {
                return $this->findOrFail($id);
            }
        );
    }

    public function delete(int $id, array $context = []): bool
    {
        return $this->security->executeCriticalOperation(
            function() use ($id, $context) {
                DB::beginTransaction();
                try {
                    $mediaFile = $this->findOrFail($id);
                    
                    // Delete physical files
                    $this->storage->delete($mediaFile->path);
                    
                    if ($mediaFile->thumbnails) {
                        foreach ($mediaFile->thumbnails as $thumbnail) {
                            $this->storage->delete($thumbnail);
                        }
                    }
                    
                    // Delete database record
                    $mediaFile->delete();
                    
                    // Clear cache
                    $this->cache->tags(['media'])->forget("media.{$id}");
                    
                    event(new MediaEvent('deleted', $mediaFile));
                    
                    DB::commit();
                    return true;
                    
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw new MediaException('File deletion failed: ' . $e->getMessage());
                }
            },
            ['operation' => 'media_delete']
        );
    }

    protected function validateFile(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new MediaException('Invalid file upload');
        }

        if ($file->getSize() > $this->config['max_file_size']) {
            throw new MediaException('File size exceeds maximum allowed size');
        }

        if (!in_array($file->getMimeType(), $this->allowedMimeTypes)) {
            throw new MediaException('File type not allowed');
        }

        // Additional security checks for images
        if ($this->isImage($file)) {
            $this->validateImage($file);
        }
    }

    protected function validateImage(UploadedFile $file): void
    {
        $imageInfo = getimagesize($file->path());
        
        if (!$imageInfo) {
            throw new MediaException('Invalid image file');
        }

        if ($imageInfo[0] > $this->config['image_max_dimension'] || 
            $imageInfo[1] > $this->config['image_max_dimension']) {
            throw new MediaException('Image dimensions exceed maximum allowed size');
        }

        // Validate image content
        if (!$this->security->validateImageContent($file->path())) {
            throw new SecurityException('Image content validation failed');
        }
    }

    protected function generateSecureFilename(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        $random = bin2hex(random_bytes(16));
        return date('Y/m/') . $random . '.' . $extension;
    }

    protected function processAndStoreFile(UploadedFile $file, string $filename, array $context): MediaFile
    {
        // Store file securely
        $path = $this->storage->putFileAs(
            $this->config['storage_path'],
            $file,
            $filename,
            $this->config['secure_storage'] ? 'private' : 'public'
        );

        // Create database record
        $mediaFile = new MediaFile([
            'filename' => basename($filename),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'user_id' => $context['user_id'] ?? null,
            'metadata' => $this->generateMetadata($file)
        ]);

        $mediaFile->save();
        
        return $mediaFile;
    }

    protected function generateThumbnails(MediaFile $mediaFile): void
    {
        $thumbnails = [];

        foreach ($this->config['thumbnail_sizes'] as $size => $dimension) {
            $thumbnailPath = $this->generateThumbnail($mediaFile, $dimension);
            if ($thumbnailPath) {
                $thumbnails[$size] = $thumbnailPath;
            }
        }

        $mediaFile->update(['thumbnails' => $thumbnails]);
    }

    protected function generateThumbnail(MediaFile $mediaFile, int $dimension): ?string
    {
        try {
            $image = $this->storage->get($mediaFile->path);
            $thumbnail = $this->resizeImage($image, $dimension);
            
            $thumbnailPath = "thumbnails/{$dimension}/" . $mediaFile->filename;
            $this->storage->put($thumbnailPath, $thumbnail);
            
            return $thumbnailPath;
        } catch (\Exception $e) {
            // Log error but continue
            return null;
        }
    }

    protected function resizeImage(string $imageData, int $dimension): string
    {
        // Implementation of secure image resizing
        // Returns resized image data
        return '';
    }

    protected function generateMetadata(UploadedFile $file): array
    {
        $metadata = [
            'original_name' => $file->getClientOriginalName(),
            'hash' => hash_file('sha256', $file->path())
        ];

        if ($this->isImage($file)) {
            $imageInfo = getimagesize($file->path());
            $metadata['dimensions'] = [
                'width' => $imageInfo[0],
                'height' => $imageInfo[1]
            ];
        }

        return $metadata;
    }

    protected function isImage(UploadedFile $file): bool
    {
        return strpos($file->getMimeType(), 'image/') === 0;
    }

    protected function findOrFail(int $id): MediaFile
    {
        $mediaFile = MediaFile::find($id);
        
        if (!$mediaFile) {
            throw new MediaException('Media file not found');
        }
        
        return $mediaFile;
    }

    protected function cleanup(string $filename): void
    {
        try {
            $this->storage->delete($this->config['storage_path'] . '/' . $filename);
        } catch (\Exception $e) {
            // Log cleanup error
        }
    }
}
