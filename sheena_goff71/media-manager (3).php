<?php

namespace App\Core\Media;

use Illuminate\Support\Facades\Storage;
use App\Core\Security\SecurityManagerInterface;
use App\Core\Validation\ValidationServiceInterface;
use App\Core\Monitoring\MonitoringServiceInterface;
use App\Core\Infrastructure\InfrastructureManagerInterface;
use App\Exceptions\MediaException;

class MediaManager implements MediaManagerInterface
{
    private SecurityManagerInterface $security;
    private ValidationServiceInterface $validator;
    private MonitoringServiceInterface $monitor;
    private InfrastructureManagerInterface $infrastructure;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        ValidationServiceInterface $validator,
        MonitoringServiceInterface $monitor,
        InfrastructureManagerInterface $infrastructure,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->monitor = $monitor;
        $this->infrastructure = $infrastructure;
        $this->config = $config;
    }

    /**
     * Secure file upload with comprehensive validation and monitoring
     */
    public function uploadFile(UploadedFile $file, array $context): MediaFile
    {
        $operationId = $this->monitor->startOperation('media.upload');

        try {
            // Validate file before processing
            $this->validateFile($file, $context);

            // Process file with security checks
            $processedFile = $this->processFileSecurely($file, $operationId);

            // Store file with encryption
            $path = $this->storeFileSecurely($processedFile, $context);

            // Create media record
            $media = $this->createMediaRecord($path, $processedFile, $context);

            // Generate optimized versions
            $this->generateOptimizedVersions($media, $processedFile);

            $this->monitor->recordMetric('media.upload.success', 1);
            return $media;

        } catch (\Throwable $e) {
            $this->handleUploadFailure($e, $operationId);
            throw $e;
        } finally {
            $this->cleanupTemporaryFiles($operationId);
            $this->monitor->stopOperation($operationId);
        }
    }

    /**
     * Secure file retrieval with access control
     */
    public function getFile(string $fileId, array $context): MediaFile
    {
        return $this->security->executeCriticalOperation(function() use ($fileId, $context) {
            // Validate access permissions
            if (!$this->security->checkPermission($context['user'], "media.read.$fileId")) {
                throw new MediaException('Access denied to media file');
            }

            $media = $this->findMedia($fileId);
            
            // Verify file integrity
            $this->verifyFileIntegrity($media);

            // Track access
            $this->monitor->recordMetric('media.access', [
                'file_id' => $fileId,
                'user_id' => $context['user']->id
            ]);

            return $media;
        }, $context);
    }

    /**
     * Secure file deletion with verification
     */
    public function deleteFile(string $fileId, array $context): void
    {
        $this->security->executeCriticalOperation(function() use ($fileId, $context) {
            // Validate delete permissions
            if (!$this->security->checkPermission($context['user'], "media.delete.$fileId")) {
                throw new MediaException('Access denied for file deletion');
            }

            $media = $this->findMedia($fileId);
            
            // Remove file and all versions
            $this->removeFileSecurely($media);
            
            // Remove media record
            $this->deleteMediaRecord($media);

            // Clear caches
            $this->infrastructure->invalidateCache("media.$fileId");

            $this->monitor->recordMetric('media.delete.success', 1);
        }, $context);
    }

    private function validateFile(UploadedFile $file, array $context): void
    {
        // Validate file type
        if (!in_array($file->getMimeType(), $this->config['allowed_types'])) {
            throw new MediaException('Invalid file type');
        }

        // Validate file size
        if ($file->getSize() > $this->config['max_file_size']) {
            throw new MediaException('File size exceeds limit');
        }

        // Scan for malware
        if (!$this->security->scanFile($file)) {
            throw new MediaException('Security scan failed');
        }

        // Additional custom validations
        $this->validator->validateOperation('media.upload', [
            'file' => $file,
            'context' => $context
        ]);
    }

    private function processFileSecurely(UploadedFile $file, string $operationId): ProcessedFile
    {
        return $this->infrastructure->executeWithResourceLimits(
            function() use ($file) {
                $processor = new SecureFileProcessor($this->config['processing']);
                return $processor->process($file);
            },
            [
                'memory_limit' => $this->config['process_memory_limit'],
                'time_limit' => $this->config['process_time_limit']
            ]
        );
    }

    private function storeFileSecurely(ProcessedFile $file, array $context): string
    {
        // Generate secure path
        $path = $this->security->generateSecurePath($file);

        // Encrypt file
        $encryptedContent = $this->security->encryptFile($file->getContents());

        // Store with integrity check
        Storage::put($path, $encryptedContent);

        // Verify stored file
        $this->verifyStoredFile($path, $file);

        return $path;
    }

    private function verifyFileIntegrity(MediaFile $media): void
    {
        if (!Storage::exists($media->path)) {
            throw new MediaException('Media file not found');
        }

        $storedHash = $this->security->hashFile(Storage::get($media->path));
        if ($storedHash !== $media->hash) {
            throw new MediaException('File integrity check failed');
        }
    }

    private function generateOptimizedVersions(MediaFile $media, ProcessedFile $file): void
    {
        foreach ($this->config['optimization_versions'] as $version => $settings) {
            $this->infrastructure->executeWithResourceLimits(
                function() use ($media, $file, $version, $settings) {
                    $optimized = $this->createOptimizedVersion($file, $settings);
                    $this->storeOptimizedVersion($media, $optimized, $version);
                },
                $settings['resource_limits']
            );
        }
    }

    private function handleUploadFailure(\Throwable $e, string $operationId): void
    {
        $this->monitor->recordMetric('media.upload.failure', 1);
        $this->monitor->triggerAlert('media_upload_failed', [
            'operation_id' => $operationId,
            'error' => $e->getMessage()
        ]);
    }

    private function cleanupTemporaryFiles(string $operationId): void
    {
        // Implement secure cleanup of temporary files
    }
}
