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
            // Validate file and metadata
            $this->validator->validateFile($file);
            $this->validator->validateMetadata($metadata);
            
            // Security check
            $this->security->validateOperation('media.upload', $context);

            // Store file
            $path = $this->storage->store($file);
            
            // Create media record
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

            // Record metrics
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
            $media = $this->repository->findById($mediaId);
            
            $this->security->validateAccess($media, $context);
            $this->security->validateOperation('media.delete', $context);
            
            $this->storage->delete($media->path);
            $this->repository->delete($mediaId);