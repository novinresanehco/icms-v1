<?php

namespace App\Core\Media;

use App\Core\Security\SecurityManager;
use App\Core\Storage\StorageManager;
use App\Core\Protection\CoreProtectionSystem;
use App\Core\Exceptions\{MediaException, SecurityException};

class MediaManager implements MediaManagerInterface
{
    private SecurityManager $security;
    private StorageManager $storage;
    private CoreProtectionSystem $protection;
    private ValidationService $validator;
    private MetricsCollector $metrics;

    public function store(UploadedFile $file, SecurityContext $context): MediaFile
    {
        return $this->protection->executeProtectedOperation(
            function() use ($file, $context) {
                $validated = $this->validateFile($file);
                $sanitized = $this->sanitizeFile($validated);
                
                $mediaFile = $this->storage->store($sanitized, [
                    'encryption' => 'aes-256-gcm',
                    'access_control' => $context->getAccessLevel(),
                    'audit_trail' => true
                ]);

                $this->processFile($mediaFile);
                return $mediaFile;
            },
            $context
        );
    }

    public function retrieve(string $id, SecurityContext $context): MediaFile
    {
        return $this->protection->executeProtectedOperation(
            function() use ($id, $context) {
                $mediaFile = $this->storage->get($id);
                
                if (!$this->security->validateAccess($context, $mediaFile)) {
                    throw new SecurityException('Access denied to media file');
                }

                return $this->decryptFile($mediaFile, $context);
            },
            $context
        );
    }

    public function process(MediaFile $file, SecurityContext $context): void
    {
        $this->protection->executeProtectedOperation(
            function() use ($file, $context) {
                $this->validateProcessingRights($context, $file);
                
                foreach ($this->getProcessingPipeline($file) as $processor) {
                    $processor->process($file);
                    $this->validateProcessedFile($file);
                    $this->auditProcessing($file, $processor);
                }

                $this->storage->update($file);
            },
            $context
        );
    }

    public function delete(string $id, SecurityContext $context): void
    {
        $this->protection->executeProtectedOperation(
            function() use ($id, $context) {
                $file = $this->storage->get($id);
                
                if (!$this->security->validateDeleteAccess($context, $file)) {
                    throw new SecurityException('Delete access denied');
                }

                $this->secureDelete($file);
                $this->auditDeletion($file, $context);
            },
            $context
        );
    }

    private function validateFile(UploadedFile $file): UploadedFile
    {
        $rules = [
            'size' => 'max:' . config('media.max_size'),
            'mimeTypes' => config('media.allowed_types'),
            'malware' => 'clean',
            'metadata' => 'verified'
        ];

        if (!$this->validator->validate($file, $rules)) {
            throw new MediaException('File validation failed');
        }

        return $file;
    }

    private function sanitizeFile(UploadedFile $file): UploadedFile
    {
        return $this->security->executeCriticalOperation(
            new SanitizeFileOperation($file),
            $this->context
        );
    }

    private function processFile(MediaFile $file): void
    {
        $processors = [
            new VirusScan($file),
            new MetadataCleaner($file),
            new ImageOptimizer($file),
            new WatermarkApplier($file)
        ];

        foreach ($processors as $processor) {
            $processor->process();
            $this->verifyProcessing($file, $processor);
        }
    }

    private function decryptFile(MediaFile $file, SecurityContext $context): MediaFile
    {
        return $this->security->executeCriticalOperation(
            new DecryptFileOperation($file, $context),
            $context
        );
    }

    private function secureDelete(MediaFile $file): void
    {
        $this->storage->secureDelete($file, [
            'passes' => 3,
            'verify' => true,
            'audit' => true
        ]);
    }

    private function verifyProcessing(MediaFile $file, FileProcessor $processor): void
    {
        if (!$processor->verify($file)) {
            throw new MediaException("Processing verification failed for {$processor->getName()}");
        }

        $this->metrics->recordProcessing($file, $processor);
    }

    private function auditProcessing(MediaFile $file, FileProcessor $processor): void
    {
        $this->security->auditLog->record([
            'action' => 'file_processed',
            'file_id' => $file->getId(),
            'processor' => $processor->getName(),
            'timestamp' => now(),
            'status' => 'success'
        ]);
    }
}
