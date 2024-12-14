<?php

namespace App\Core\Media;

final class MediaManager
{
    private SecurityManager $security;
    private StorageService $storage;
    private ValidationService $validator;
    private ProcessorService $processor;
    private AuditService $audit;

    public function __construct(
        SecurityManager $security,
        StorageService $storage,
        ValidationService $validator,
        ProcessorService $processor,
        AuditService $audit
    ) {
        $this->security = $security;
        $this->storage = $storage;
        $this->validator = $validator;
        $this->processor = $processor;
        $this->audit = $audit;
    }

    public function handleUpload(UploadedFile $file, SecurityContext $context): Media
    {
        return $this->security->executeCriticalOperation(function() use ($file, $context) {
            // Validate file
            $this->validator->validateFile($file);
            
            // Create file hash
            $hash = $this->createFileHash($file);
            
            // Check for duplicates
            if ($existing = $this->findByHash($hash)) {
                return $existing;
            }
            
            // Process file
            $processed = $this->processFile($file);
            
            // Store file securely
            $path = $this->storage->store($processed, 'media');
            
            // Create database record
            $media = $this->createMediaRecord([
                'path' => $path,
                'hash' => $hash,
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'metadata' => $this->extractMetadata($file)
            ]);
            
            // Audit trail
            $this->audit->logMediaUpload($media, $context);
            
            return $media;
        }, $context);
    }

    public function processFile(UploadedFile $file): ProcessedFile
    {
        // Check file type
        $type = $this->determineFileType($file);
        
        // Initialize processor
        $processor = $this->getProcessor($type);
        
        // Process with protection
        return $processor->process($file, [
            'optimize' => true,
            'sanitize' => true,
            'verify' => true
        ]);
    }

    public function createThumbnails(Media $media): array
    {
        if (!$this->canCreateThumbnails($media)) {
            throw new UnsupportedMediaTypeException('Cannot create thumbnails for this media type');
        }

        $processor = $this->getProcessor($media->type);
        
        // Generate thumbnails with error checking
        try {
            $thumbnails = $processor->createThumbnails($media->path, [
                'sizes' => config('media.thumbnail_sizes'),
                'quality' => config('media.thumbnail_quality')
            ]);
            
            // Update media record
            $media->update(['thumbnails' => $thumbnails]);
            
            return $thumbnails;
            
        } catch (\Throwable $e) {
            $this->audit->logMediaProcessingError($media, 'thumbnail_creation', $e);
            throw $e;
        }
    }

    public function deleteMedia(int $id, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(function() use ($id, $context) {
            $media = $this->findOrFail($id);
            
            // Remove physical file
            $this->storage->delete($media->path);
            
            // Remove thumbnails if exist
            if ($media->hasThumbnails()) {
                foreach ($media->thumbnails as $thumbnail) {
                    $this->storage->delete($thumbnail);
                }
            }
            
            // Delete record
            $deleted = $media->delete();
            
            // Audit trail
            $this->audit->logMediaDeletion($media, $context);
            
            return $deleted;
        }, $context);
    }

    private function createFileHash(UploadedFile $file): string
    {
        return hash_file('sha256', $file->path());
    }

    private function extractMetadata(UploadedFile $file): array
    {
        return [
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'dimensions' => $this->getImageDimensions($file),
            'created_at' => time()
        ];
    }

    private function determineFileType(UploadedFile $file): string
    {
        $mime = $file->getMimeType();
        
        foreach ($this->getAllowedTypes() as $type => $mimes) {
            if (in_array($mime, $mimes)) {
                return $type;
            }
        }
        
        throw new UnsupportedMediaTypeException("Unsupported file type: {$mime}");
    }

    private function getProcessor(string $type): ProcessorInterface
    {
        if (!isset($this->processors[$type])) {
            throw new \RuntimeException("No processor found for type: {$type}");
        }
        
        return $this->processors[$type];
    }
}
