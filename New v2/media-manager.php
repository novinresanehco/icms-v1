<?php

namespace App\Core\Media;

use App\Core\Security\SecurityContext;
use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class MediaManager implements MediaManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private MediaRepository $repository;
    private StorageManager $storage;
    private MetricsCollector $metrics;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        MediaRepository $repository,
        StorageManager $storage,
        MetricsCollector $metrics
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->repository = $repository;
        $this->storage = $storage;
        $this->metrics = $metrics;
    }

    public function upload(UploadedFile $file, array $metadata, SecurityContext $context): Media
    {
        $startTime = microtime(true);
        DB::beginTransaction();

        try {
            // Security and validation
            $this->validator->validateFile($file);
            $this->validator->validateMetadata($metadata);
            $this->security->validateOperation('media.upload', $context);

            // Critical file storage
            $path = $this->storage->store($file);
            
            // Create media record with audit
            $media = $this->repository->create([
                'path' => $path,
                'filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'metadata' => $metadata,
                'user_id' => $context->getUserId(),
                'audit_trail' => [
                    'uploaded_by' => $context->getUserId(),
                    'ip_address' => $context->getIpAddress(),
                    'timestamp' => now()
                ]
            ]);

            DB::commit();
            
            // Performance metrics
            $this->metrics->record('media.upload', [
                'duration' => microtime(true) - $startTime,
                'size' => $file->getSize(),
                'success' => true
            ]);

            return $media;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->storage->cleanup($path ?? null);
            
            $this->metrics->record('media.upload', [
                'duration' => microtime(true) - $startTime,
                'error' => $e->getMessage(),
                'success' => false
            ]);

            throw new MediaException('Upload failed: ' . $e->getMessage());
        }
    }

    public function delete(int $mediaId, SecurityContext $context): void
    {
        DB::beginTransaction();

        try {
            // Access control
            $media = $this->repository->findById($mediaId);
            $this->security->validateAccess($media, $context);
            $this->security->validateOperation('media.delete', $context);

            // Critical deletion
            $this->storage->delete($media->path);
            $this->repository->delete($mediaId);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            throw new MediaException('Delete failed: ' . $e->getMessage());
        }
    }

    public function get(int $mediaId, SecurityContext $context): Media
    {
        // Security validation
        $media = $this->repository->findById($mediaId);
        $this->security->validateAccess($media, $context);

        // Access logging
        $this->metrics->record('media.access', [
            'media_id' => $mediaId,
            'user_id' => $context->getUserId(),
            'timestamp' => now()
        ]);

        return $media;
    }

    public function update(int $mediaId, array $metadata, SecurityContext $context): Media 
    {
        DB::beginTransaction();

        try {
            // Security and validation
            $media = $this->repository->findById($mediaId);
            $this->security->validateAccess($media, $context);
            $this->security->validateOperation('media.update', $context);
            $this->validator->validateMetadata($metadata);

            // Update with audit
            $media = $this->repository->update($mediaId, [
                'metadata' => $metadata,
                'audit_trail' => [
                    'updated_by' => $context->getUserId(),
                    'timestamp' => now()
                ]
            ]);

            DB::commit();
            return $media;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new MediaException('Update failed: ' . $e->getMessage());
        }
    }
}
