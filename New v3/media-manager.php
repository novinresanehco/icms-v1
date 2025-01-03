<?php

namespace App\Core\Media;

use App\Core\Security\SecurityManager;
use App\Core\Storage\StorageManager;
use App\Core\Cache\CacheManager;
use App\Core\Monitoring\MonitoringService;
use App\Core\Database\DatabaseManager;
use App\Core\Exceptions\MediaException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManager;

/**
 * Core Media Management System
 * CRITICAL COMPONENT - Handles all media operations with maximum security and optimization
 */
class MediaManager
{
    private SecurityManager $security;
    private StorageManager $storage;
    private CacheManager $cache;
    private MonitoringService $monitor;
    private DatabaseManager $database;
    private ImageManager $imageProcessor;
    
    // Allowed mime types with their extensions
    private const ALLOWED_TYPES = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'application/pdf' => ['pdf'],
        'video/mp4' => ['mp4'],
        'audio/mpeg' => ['mp3']
    ];
    
    // Maximum file sizes in bytes
    private const MAX_SIZES = [
        'image' => 10485760,  // 10MB
        'video' => 104857600, // 100MB
        'audio' => 52428800,  // 50MB
        'document' => 20971520 // 20MB
    ];

    /**
     * Initialize with all critical dependencies
     */
    public function __construct(
        SecurityManager $security,
        StorageManager $storage,
        CacheManager $cache,
        MonitoringService $monitor,
        DatabaseManager $database,
        ImageManager $imageProcessor
    ) {
        $this->security = $security;
        $this->storage = $storage;
        $this->cache = $cache;
        $this->monitor = $monitor;
        $this->database = $database;
        $this->imageProcessor = $imageProcessor;
    }

    /**
     * Processes and stores uploaded media with security validation
     *
     * @param UploadedFile $file The uploaded file
     * @param array $options Processing options
     * @return MediaFile
     * @throws MediaException
     */
    public function processUpload(UploadedFile $file, array $options = []): MediaFile
    {
        $operationId = $this->monitor->startOperation('media.upload');
        
        try {
            // Validate file security
            $this->validateFile($file);
            
            DB::beginTransaction();
            
            // Process the file based on type
            $processedFile = $this->processFile($file, $options);
            
            // Store file metadata
            $metadata = $this->storeMetadata($processedFile);
            
            // Store the actual file
            $path = $this->storage->storeSecurely(
                $processedFile['path'],
                $this->generateSecurePath($metadata->id)
            );
            
            // Update metadata with final path
            $metadata = $this->database->update('media', $metadata->id, ['path' => $path]);
            
            // Generate thumbnails if image
            if ($this->isImage($file->getMimeType())) {
                $this->generateThumbnails($path, $metadata->id);
            }
            
            DB::commit();
            
            // Cache the metadata
            $this->cache->set("media.{$metadata->id}", $metadata);
            
            Log::info('Media uploaded successfully', [
                'id' => $metadata->id,
                'type' => $file->getMimeType()
            ]);
            
            return $metadata;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            
            // Clean up any temporary files
            $this->cleanup($file);
            
            Log::error('Media upload failed', [
                'error' => $e->getMessage(),
                'file' => $file->getClientOriginalName()
            ]);
            
            throw new MediaException(
                'Failed to process media: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    /**
     * Retrieves media with security checks and optimization
     */
    public function retrieve(int $id, array $options = []): ?MediaFile
    {
        $operationId = $this->monitor->startOperation('media.retrieve');
        
        try {
            // Verify access permissions
            $this->security->validateMediaAccess($id);
            
            // Check cache first
            $cached = $this->cache->get("media.{$id}");
            if ($cached) {
                return $cached;
            }
            
            // Get from database
            $media = $this->database->find('media', $id);
            if (!$media) {
                return null;
            }
            
            // Apply any transformations
            if (!empty($options['transform'])) {
                $media = $this->transformMedia($media, $options['transform']);
            }
            
            // Cache for next time
            $this->cache->set("media.{$id}", $media);
            
            return $media;
            
        } catch (\Throwable $e) {
            Log::error('Media retrieval failed', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            
            throw new MediaException(
                'Failed to retrieve media: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    /**
     * Deletes media with security validation
     */
    public function delete(int $id): bool
    {
        $operationId = $this->monitor->startOperation('media.delete');
        
        try {
            // Verify delete permissions
            $this->security->validateMediaDeletion($id);
            
            DB::beginTransaction();
            
            // Get media info
            $media = $this->database->find('media', $id);
            if (!$media) {
                throw new MediaException('Media not found');
            }
            
            // Delete file and thumbnails
            $this->storage->deleteSecurely($media->path);
            $this->deleteThumbnails($id);
            
            // Delete from database
            $this->database->delete('media', $id);
            
            // Remove from cache
            $this->cache->delete("media.{$id}");
            
            DB::commit();
            
            Log::info('Media deleted successfully', ['id' => $id]);
            
            return true;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            
            Log::error('Media deletion failed', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);
            
            throw new MediaException(
                'Failed to delete media: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    /**
     * Validates file security and constraints
     */
    private function validateFile(UploadedFile $file): void
    {
        // Check mime type
        if (!array_key_exists($file->getMimeType(), self::ALLOWED_TYPES)) {
            throw new MediaException('File type not allowed');
        }
        
        // Check file size
        $category = $this->getFileCategory($file->getMimeType());
        if ($file->getSize() > self::MAX_SIZES[$category]) {
            throw new MediaException('File size exceeds limit');
        }
        
        // Scan for malware
        if (!$this->security->scanFile($file)) {
            throw new MediaException('File failed security scan');
        }
    }

    /**
     * Processes file based on type and options
     */
    private function processFile(UploadedFile $file, array $options): array
    {
        $mimeType = $file->getMimeType();
        
        if ($this->isImage($mimeType)) {
            return $this->processImage($file, $options);
        }
        
        if ($this->isVideo($mimeType)) {
            return $this->processVideo($file, $options);
        }
        
        // Default processing for other files
        return [
            'path' => $file->getPathname(),
            'processed' => false
        ];
    }

    /**
     * Processes image files with optimization
     */
    private function processImage(UploadedFile $file, array $options): array
    {
        $image = $this->imageProcessor->make($file);
        
        // Apply optimizations
        if (!empty($options['optimize'])) {
            $image->optimize();
        }
        
        // Resize if needed
        if (!empty($options['maxWidth'])) {
            $image->resize($options['maxWidth'], null, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
        }
        
        // Save processed image
        $path = tempnam(sys_get_temp_dir(), 'media_');
        $image->save($path, !empty($options['quality']) ? $options['quality'] : 90);
        
        return [
            'path' => $path,
            'processed' => true
        ];
    }

    /**
     * Generates thumbnails for images
     */
    private function generateThumbnails(string $path, int $mediaId): void
    {
        $sizes = [
            'small' => 150,
            'medium' => 300,
            'large' => 600
        ];
        
        foreach ($sizes as $size => $width) {
            $thumbnail = $this->imageProcessor->make($path);
            $thumbnail->resize($width, null, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
            
            $thumbnailPath = $this->generateThumbnailPath($mediaId, $size);
            $this->storage->storeSecurely(
                $thumbnail->save(tempnam(sys_get_temp_dir(), 'thumb_')),
                $thumbnailPath
            );
        }
    }

    /**
     * Stores media metadata in database
     */
    private function storeMetadata(array $processedFile): MediaFile
    {
        $metadata = [
            'path' => $processedFile['path'],
            'size' => filesize($processedFile['path']),
            'mime_type' => mime_content_type($processedFile['path']),
            'processed' => $processedFile['processed'],
            'created_at' => now(),
            'created_by' => auth()->id()
        ];
        
        return $this->database->store('media', $metadata);
    }

    /**
     * Generates secure storage path
     */
    private function generateSecurePath(int $id): string
    {
        return sprintf(
            'media/%s/%d/%s',
            date('Y/m'),
            $id,
            $this->security->generateSecureFilename()
        );
    }

    /**
     * Generates thumbnail path
     */
    private function generateThumbnailPath(int $id, string $size): string
    {
        return sprintf(
            'media/thumbnails/%d/%s_%s',
            $id,
            $size,
            $this->security->generateSecureFilename()
        );
    }

    /**
     * Determines if mime type is an image
     */
    private function isImage(string $mimeType): bool
    {
        return strpos($mimeType, 'image/') === 0;
    }

    /**
     * Determines if mime type is a video
     */
    private function isVideo(string $mimeType): bool
    {
        return strpos($mimeType, 'video/') === 0;
    }

    /**
     * Gets file category from mime type
     */
    private function getFileCategory(string $mimeType): string
    {
        if ($this->isImage($mimeType)) return 'image';
        if ($this->isVideo($mimeType)) return 'video';
        if (strpos($mimeType, 'audio/') === 0) return 'audio';
        return 'document';
    }

    /**
     * Cleans up temporary files
     */
    private function cleanup(UploadedFile $file): void
    {
        try {
            @unlink($file->getPathname());
        } catch (\Throwable $e) {
            Log::warning('Failed to cleanup temporary file', [
                'file' => $file->getPathname(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Transforms media based on options
     */
    private function transformMedia(MediaFile $media, array $options): MediaFile
    {
        if (!$this->isImage($media->mime_type)) {
            return $media;
        }
        
        // Apply transformations
        $image = $this->imageProcessor->make($media->path);
        
        foreach ($options as $transform => $value) {
            switch ($transform) {
                case 'resize':
                    $image->resize($value['width'], $value['height'] ?? null);
                    break;
                case 'crop':
                    $image->crop($value['width'], $value['height']);
                    break;
                case 'rotate':
                    $image->rotate($value);
                    break;
            }
        }
        
        // Save transformed image
        $newPath = $this->generateSecurePath($media->id);
        $this->storage->storeSecurely($image->save()->getPathname(), $newPath);
        
        $media->path = $newPath;
        return $media;
    }
}
