<?php

namespace App\Core\CMS;

use App\Core\Security\SecurityManager;
use App\Core\Protection\SystemProtection;
use App\Core\Data\TransactionManager;

class MediaManager
{
    private SecurityManager $security;
    private SystemProtection $protection;
    private TransactionManager $transaction;
    private StorageService $storage;
    private ImageProcessor $imageProcessor;

    public function __construct(
        SecurityManager $security,
        SystemProtection $protection,
        TransactionManager $transaction,
        StorageService $storage,
        ImageProcessor $imageProcessor
    ) {
        $this->security = $security;
        $this->protection = $protection;
        $this->transaction = $transaction;
        $this->storage = $storage;
        $this->imageProcessor = $imageProcessor;
    }

    public function uploadMedia(UploadedFile $file, array $context): Media
    {
        return $this->security->executeCriticalOperation(function() use ($file, $context) {
            return $this->protection->executeProtectedOperation(function() use ($file, $context) {
                return $this->transaction->executeTransaction(function() use ($file) {
                    // Validate file
                    if (!$this->validator->validateFile($file)) {
                        throw new InvalidMediaException('Invalid file');
                    }
                    
                    // Store with protection
                    $path = $this->storage->store($file, 'media');
                    
                    // Create media record
                    $media = Media::create([
                        'path' => $path,
                        'mime_type' => $file->getMimeType(),
                        'size' => $file->getSize(),
                        'metadata' => $this->extractMetadata($file)
                    ]);
                    
                    // Process image if applicable
                    if ($this->isImage($file)) {
                        $this->processImage($media, $file);
                    }
                    
                    return $media;
                }, $context);
            }, $context);
        }, $context);
    }

    public function deleteMedia(int $id, array $context): bool
    {
        return $this->security->executeCriticalOperation(function() use ($id, $context) {
            return $this->protection->executeProtectedOperation(function() use ($id, $context) {
                return $this->transaction->executeTransaction(function() use ($id) {
                    $media = $this->findOrFail($id);
                    
                    // Remove physical file
                    $this->storage->delete($media->path);
                    
                    // Remove thumbnails if exists
                    if ($media->hasThumbnails()) {
                        $this->removeThumbnails($media);
                    }
                    
                    // Delete record
                    return $media->delete();
                }, $context);
            }, $context);
        }, $context);
    }

    protected function processImage(Media $media, UploadedFile $file): void
    {
        // Generate thumbnails
        $thumbnails = $this->imageProcessor->createThumbnails($file);
        
        // Store thumbnail paths
        $media->update([
            'thumbnails' => $thumbnails,
            'dimensions' => $this->imageProcessor->getDimensions($file)
        ]);
    }

    protected function isImage(UploadedFile $file): bool
    {
        return str_starts_with($file->getMimeType(), 'image/');
    }

    protected function extractMetadata(UploadedFile $file): array
    {
        return [
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_at' => now()
        ];
    }

    protected function findOrFail(int $id): Media
    {
        if (!$media = Media::find($id)) {
            throw new MediaNotFoundException("Media not found: {$id}");
        }
        return $media;
    }

    protected function removeThumbnails(Media $media): void
    {
        foreach ($media->thumbnails as $path) {
            $this->storage->delete($path);
        }
    }
}
