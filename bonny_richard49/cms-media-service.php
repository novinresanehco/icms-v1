<?php

namespace App\Core\CMS\Media;

use App\Core\CMS\BaseCmsService;

class MediaService extends BaseCmsService
{
    private MediaRepository $repository;
    private MediaProcessor $processor;
    private StorageManager $storage;
    private MediaValidator $mediaValidator;

    protected function getValidationRules(): array
    {
        return [
            'file' => 'required|file|max:' . $this->config['max_file_size'],
            'mime_type' => ['required', 'string', Rule::in($this->config['allowed_mime_types'])],
            'filename' => 'required|string|max:255',
            'folder_id' => 'nullable|exists:media_folders,id',
            'metadata' => 'nullable|array',
            'visibility' => ['required', Rule::in(['public', 'private', 'protected'])]
        ];
    }

    protected function generateCacheKey(string $operation, array $context): string
    {
        return sprintf(
            'media:%s:%s',
            $operation,
            md5(json_encode($context))
        );
    }

    protected function executeCreate(array $data, array $context)
    {
        try {
            // Validate media file
            $this->mediaValidator->validateFile($data['file']);

            // Process media file
            $processed = $this->processor->processUpload(
                $data['file'], 
                $this->getProcessingOptions($data)
            );

            // Store media file
            $stored = $this->storage->store(
                $processed['file'],
                $this->getStoragePath($data),
                $this->getStorageOptions($data)
            );

            // Create media record
            return $this->repository->create([
                'filename' => $data['filename'],
                'path' => $stored['path'],
                'mime_type' => $data['mime_type'],
                'size' => $stored['size'],
                'metadata' => $this->processMetadata($data, $processed),
                'folder_id' => $data['folder_id'] ?? null,
                'visibility' => $data['visibility'],
                'storage_provider' => $stored['provider']
            ]);

        } catch (\Throwable $e) {
            // Cleanup on failure
            $this->cleanupFailedUpload($processed['file'] ?? null, $stored['path'] ?? null);
            throw $e;
        }
    }

    protected function executeUpdate(array $data, array $context)
    {
        // Validate media exists
        $media = $this->repository->findOrFail($context['id']);

        // Handle file update if new file provided
        if (isset($data['file'])) {
            $this->handleFileUpdate($media, $data);
        }

        // Update media record
        return $this->repository->update($media->id, [
            'filename' => $data['filename'] ?? $media->filename,
            'metadata' => $this->mergeMetadata($media->metadata, $data['metadata'] ?? []),
            'folder_id' => $data['folder_id'] ?? $media->folder_id,
            'visibility' => $data['visibility'] ?? $media->visibility
        ]);
    }

    protected function executeDelete(array $data, array $context)
    {
        // Validate media exists
        $media = $this->repository->findOrFail($context['id']);

        // Check for dependencies
        $this->checkDependencies($media);

        // Delete file from storage
        $this->storage->delete($media->path);

        // Delete media record
        return $this->repository->delete($media->id);
    }

    protected function handleFileUpdate(Media $media, array $data): void
    {
        // Validate new file
        $this->mediaValidator->validateFile($data['file']);

        // Process new file
        $processed = $this->processor->processUpload(
            $data['file'],
            $this->getProcessingOptions($data)
        );

        // Store new file
        $stored = $this->storage->store(
            $processed['file'],
            $this->getStoragePath($data),
            $this->getStorageOptions($data)
        );

        // Delete old file
        $this->storage->delete($media->path);

        // Update media record with new file info
        $media->update([
            'path' => $stored['path'],
            'mime_type' => $data['mime_type'],
            'size' => $stored['size'],
            'storage_provider' => $stored['provider']
        ]);
    }

    protected function checkDependencies(Media $media): void
    {
        if ($this->repository->hasDependencies($media->id)) {
            throw new MediaDependencyException(
                'Media has active dependencies and cannot be deleted'
            );
        }
    }

    protected function getProcessingOptions(array $data): array
    {
        return [
            'optimize' => true,
            'max_width' => $this->config['max_image_width'],
            'max_height' => $this->config['max_image_height'],
            'preserve_quality' => true
        ];
    }

    protected function getStoragePath(array $data): string
    {
        return sprintf(
            '%s/%s/%s',
            date('Y/m'),
            $data['visibility'],
            $this->generateUniqueFilename($data['filename'])
        );
    }

    protected function getStorageOptions(array $data): array
    {
        return [
            'visibility' => $data['visibility'],
            'mime_type' => $data['mime_type'],
            'metadata' => $data['metadata'] ?? []
        ];
    }

    protected function processMetadata(array $data, array $processed): array
    {
        return array_merge(
            $data['metadata'] ?? [],
            $processed['metadata'] ?? [],
            [
                'processed_at' => now(),
                'original_filename' => $data['file']->getClientOriginalName(),
                'dimensions' => $processed['dimensions'] ?? null,
                'hash' => hash_file('sha256', $data['file']->path())
            ]
        );
    }

    protected function cleanupFailedUpload(?string $processedFile, ?string $storedPath): void
    {
        try {
            if ($processedFile && file_exists($processedFile)) {
                unlink($processedFile);
            }

            if ($storedPath) {
                $this->storage->delete($storedPath);
            }
        } catch (\Throwable $e) {
            // Log cleanup failure but don't throw
            $this->logger->error('Failed to cleanup after failed upload', [
                'error' => $e->getMessage(),
                'processed_file' => $processedFile,
                'stored_path' => $storedPath
            ]);
        }
    }

    protected function generateUniqueFilename(string $filename): string
    {
        return sprintf(
            '%s_%s_%s',
            pathinfo($filename, PATHINFO_FILENAME),
            uniqid(),
            pathinfo($filename, PATHINFO_EXTENSION)
        );
    }

    protected function notifyAdministrators(
        \Throwable $e,
        string $operation,
        array $context
    ): void {
        foreach ($this->config['admin_notification_channels'] as $channel) {
            $channel->notify([
                'type' => 'media_operation_failure',
                'operation' => $operation,
                'context' => $context,
                'error' => $e->getMessage(),
                'severity' => 'CRITICAL'
            ]);
        }
    }
}
