<?php

namespace App\Core\Media;

use App\Core\Security\CoreSecurityManager;
use App\Core\Services\ValidationService;
use App\Core\Services\AuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Core\Exceptions\MediaException;

class MediaManager
{
    private CoreSecurityManager $security;
    private ValidationService $validator;
    private AuditService $audit;
    private array $config;

    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    ];

    public function __construct(
        CoreSecurityManager $security,
        ValidationService $validator,
        AuditService $audit,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function uploadMedia(UploadedFile $file, array $metadata = []): Media
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeUpload($file, $metadata),
            ['operation' => 'media_upload', 'filename' => $file->getClientOriginalName()]
        );
    }

    public function processMedia(int $mediaId): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeProcessing($mediaId),
            ['operation' => 'media_process', 'media_id' => $mediaId]
        );
    }

    public function deleteMedia(int $mediaId): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeDelete($mediaId),
            ['operation' => 'media_delete', 'media_id' => $mediaId]
        );
    }

    private function executeUpload(UploadedFile $file, array $metadata): Media
    {
        // Validate file
        $this->validateFile($file);

        try {
            // Generate secure filename
            $filename = $this->generateSecureFilename($file);
            
            // Scan file for threats
            $this->scanFile($file);
            
            // Store file securely
            $path = $this->storeFile($file, $filename);
            
            // Create media record
            $media = Media::create([
                'filename' => $filename,
                'original_filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'path' => $path,
                'metadata' => $metadata,
                'status' => 'pending',
                'hash' => hash_file('sha256', $file->getPathname())
            ]);

            // Queue for processing
            $this->queueForProcessing($media);

            return $media;

        } catch (\Exception $e) {
            // Clean up any stored file
            if (isset($path)) {
                Storage::delete($path);
            }
            
            throw new MediaException('Failed to upload file: ' . $e->getMessage());
        }
    }

    private function executeProcessing(int $mediaId): bool
    {
        try {
            $media = Media::findOrFail($mediaId);
            
            // Verify file integrity
            if (!$this->verifyFileIntegrity($media)) {
                throw new MediaException('File integrity check failed');
            }

            // Process based on mime type
            if ($this->isImage($media->mime_type)) {
                $this->processImage($media);
            } elseif ($this->isDocument($media->mime_type)) {
                $this->processDocument($media);
            }

            // Update media status
            $media->status = 'processed';
            $media->processed_at = now();
            $media->save();

            return true;

        } catch (\Exception $e) {
            $media->status = 'failed';
            $media->error_message = $e->getMessage();
            $media->save();
            
            throw new MediaException('Failed to process media: ' . $e->getMessage());
        }
    }

    private function executeDelete(int $mediaId): bool
    {
        try {
            $media = Media::findOrFail($mediaId);
            
            // Delete physical file
            if (!Storage::delete($media->path)) {
                throw new MediaException('Failed to delete file');
            }

            // Delete thumbnails if they exist
            if ($media->thumbnails) {
                foreach ($media->thumbnails as $thumbnail) {
                    Storage::delete($thumbnail);
                }
            }

            // Delete database record
            $media->delete();

            return true;

        } catch (\Exception $e) {
            throw new MediaException('Failed to delete media: ' . $e->getMessage());
        }
    }

    private function validateFile(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new MediaException('Invalid file upload');
        }

        if (!in_array($file->getMimeType(), self::ALLOWED_MIME_TYPES)) {
            throw new MediaException('Unsupported file type');
        }

        if ($file->getSize() > $this->config['max_file_size']) {
            throw new MediaException('File size exceeds limit');
        }
    }

    private function generateSecureFilename(UploadedFile $file): string
    {
        return sprintf(
            '%s_%s.%s',
            uniqid('', true),
            hash('sha256', $file->getClientOriginalName()),
            $file->getClientOriginalExtension()
        );
    }

    private function scanFile(UploadedFile $file): void
    {
        // Implement virus scanning
        if (!$this->isFileSafe($file)) {
            throw new MediaException('File failed security scan');
        }
    }

    private function storeFile(UploadedFile $file, string $filename): string
    {
        $path = $file->storeAs(
            $this->getStoragePath(),
            $filename,
            ['visibility' => 'private']
        );

        if (!$path) {
            throw new MediaException('Failed to store file');
        }

        return $path;
    }

    private function verifyFileIntegrity(Media $media): bool
    {
        $currentHash = hash_file('sha256', Storage::path($media->path));
        return hash_equals($media->hash, $currentHash);
    }

    private function processImage(Media $media): void
    {
        // Generate thumbnails
        $thumbnails = $this->generateThumbnails($media->path);
        
        // Optimize original
        $this->optimizeImage($media->path);
        
        // Update media record
        $media->thumbnails = $thumbnails;
        $media->save();
    }

    private function processDocument(Media $media): void
    {
        // Generate preview
        $preview = $this->generateDocumentPreview($media->path);
        
        // Extract metadata
        $metadata = $this->extractDocumentMetadata($media->path);
        
        // Update media record
        $media->preview = $preview;
        $media->metadata = array_merge($media->metadata ?? [], $metadata);
        $media->save();
    }

    private function isImage(string $mimeType): bool
    {
        return strpos($mimeType, 'image/') === 0;
    }

    private function isDocument(string $mimeType): bool
    {
        return strpos($mimeType, 'application/') === 0;
    }

    private function isFileSafe(UploadedFile $file): bool
    {
        // Implement file scanning logic
        return true;
    }

    private function getStoragePath(): string
    {
        return sprintf(
            'media/%s/%s',
            date('Y'),
            date('m')
        );
    }

    private function queueForProcessing(Media $media): void
    {
        // Add to processing queue
        ProcessMedia::dispatch($media);
    }

    private function generateThumbnails(string $path): array
    {
        // Implement thumbnail generation
        return [];
    }

    private function optimizeImage(string $path): void
    {
        // Implement image optimization
    }

    private function generateDocumentPreview(string $path): string
    {
        // Implement document preview generation
        return '';
    }

    private function extractDocumentMetadata(string $path): array
    {
        // Implement metadata extraction
        return [];
    }
}