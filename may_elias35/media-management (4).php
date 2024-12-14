<?php

namespace App\Core\Media;

use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Storage\StorageManager;
use App\Core\Processing\MediaProcessor;
use Illuminate\Support\Facades\DB;

class MediaManager implements MediaManagerInterface 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private StorageManager $storage;
    private MediaProcessor $processor;
    private MetricsCollector $metrics;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        StorageManager $storage,
        MediaProcessor $processor,
        MetricsCollector $metrics
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->storage = $storage;
        $this->processor = $processor;
        $this->metrics = $metrics;
    }

    public function uploadMedia(UploadedFile $file, array $options = []): MediaResponse
    {
        return $this->security->executeSecureOperation(function() use ($file, $options) {
            // Validate file
            $this->validateFile($file);
            
            DB::beginTransaction();
            try {
                // Store file securely
                $storagePath = $this->storeFile($file);
                
                // Process media
                $processedMedia = $this->processMedia($storagePath, $options);
                
                // Create media record
                $media = $this->createMediaRecord($processedMedia);
                
                // Generate variants
                $this->generateVariants($media, $options);
                
                // Update metadata
                $this->updateMetadata($media);
                
                DB::commit();
                
                $this->metrics->recordUpload($media);
                
                return new MediaResponse($media);
                
            } catch (\Exception $e) {
                DB::rollBack();
                $this->cleanup($storagePath ?? null);
                throw new MediaException('Upload failed: ' . $e->getMessage(), 0, $e);
            }
        }, ['operation' => 'media_upload']);
    }

    public function processMedia(int $id, array $operations): MediaResponse
    {
        return $this->security->executeSecureOperation(function() use ($id, $operations) {
            $this->validateOperations($operations);
            
            DB::beginTransaction();
            try {
                // Get media
                $media = $this->findMedia($id);
                
                // Create version
                $this->createVersion($media);
                
                // Process operations
                $result = $this->executeOperations($media, $operations);
                
                // Update media record
                $media = $this->updateMediaRecord($media, $result);
                
                DB::commit();
                
                $this->metrics->recordProcessing($media, $operations);
                
                return new MediaResponse($media);
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw new MediaException('Processing failed: ' . $e->getMessage(), 0, $e);
            }
        }, ['operation' => 'media_process', 'media_id' => $id]);
    }

    private function validateFile(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new ValidationException('Invalid file upload');
        }

        $rules = [
            'size' => $this->config['max_file_size'],
            'mimetypes' => $this->config['allowed_mimetypes'],
            'dimensions' => $this->config['image_dimensions'] ?? null
        ];

        if (!$this->validator->validateFile($file, $rules)) {
            throw new ValidationException('File validation failed');
        }
    }

    private function storeFile(UploadedFile $file): string
    {
        $hash = hash_file('sha256', $file->path());
        $path = $this->generateStoragePath($file, $hash);

        try {
            return $this->storage->secureStore(
                $file,
                $path,
                ['hash' => $hash]
            );
        } catch (\Exception $e) {
            throw new StorageException('Failed to store file securely', 0, $e);
        }
    }

    private function processMedia(string $path, array $options): array
    {
        $processed = $this->processor->process($path, [
            'optimize' => $options['optimize'] ?? true,
            'secure' => $options['secure'] ?? true,
            'metadata' => $options['preserve_metadata'] ?? false
        ]);

        $this->validateProcessedMedia($processed);

        return $processed;
    }

    private function createMediaRecord(array $processed): Media
    {
        $media = new Media([
            'path' => $processed['path'],
            'filename' => $processed['filename'],
            'mimetype' => $processed['mimetype'],
            'size' => $processed['size'],
            'hash' => $processed['hash'],
            'metadata' => $this->sanitizeMetadata($processed['metadata'])
        ]);

        $media->save();
        
        return $media;
    }

    private function generateVariants(Media $media, array $options): void
    {
        $variants = $options['variants'] ?? $this->config['default_variants'];

        foreach ($variants as $variant => $specs) {
            try {
                $variantPath = $this->processor->createVariant(
                    $media->path,
                    $variant,
                    $specs
                );

                $this->storeVariant($media, $variant, $variantPath);
            } catch (\Exception $e) {
                Log::error('Failed to generate variant', [
                    'media_id' => $media->id,
                    'variant' => $variant,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    private function executeOperations(Media $media, array $operations): array
    {
        $results = [];

        foreach ($operations as $operation => $params) {
            $results[$operation] = $this->processor->executeOperation(
                $media->path,
                $operation,
                $params
            );

            $this->validateOperationResult($operation, $results[$operation]);
        }

        return $results;
    }

    private function validateOperations(array $operations): void
    {
        foreach ($operations as $operation => $params) {
            if (!in_array($operation, $this->config['allowed_operations'])) {
                throw new ValidationException("Invalid operation: {$operation}");
            }

            if (!$this->validator->validateOperationParams($operation, $params)) {
                throw new ValidationException("Invalid parameters for operation: {$operation}");
            }
        }
    }

    private function validateProcessedMedia(array $processed): void
    {
        $required = ['path', 'filename', 'mimetype', 'size', 'hash'];

        foreach ($required as $field) {
            if (!isset($processed[$field])) {
                throw new ProcessingException("Missing required field: {$field}");
            }
        }

        if (!file_exists($processed['path'])) {
            throw new ProcessingException('Processed file does not exist');
        }
    }

    private function sanitizeMetadata(array $metadata): array
    {
        $allowed = $this->config['allowed_metadata_fields'];

        return array_intersect_key($metadata, array_flip($allowed));
    }

    private function generateStoragePath(UploadedFile $file, string $hash): string
    {
        return sprintf(
            '%s/%s/%s.%s',
            date('Y/m'),
            substr($hash, 0, 2),
            $hash,
            $file->getClientOriginalExtension()
        );
    }

    private function storeVariant(Media $media, string $variant, string $path): void
    {
        $media->variants()->create([
            'variant' => $variant,
            'path' => $path,
            'size' => filesize($path),
            'hash' => hash_file('sha256', $path)
        ]);
    }

    private function cleanup(?string $path): void
    {
        if ($path && file_exists($path)) {
            unlink($path);
        }
    }
}
