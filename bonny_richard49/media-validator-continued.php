<?php

namespace App\Core\CMS\Media;

class MediaValidator implements MediaValidationInterface 
{
    private SecurityService $security;
    private AuditLogger $logger;
    
    protected function handleValidationFailure(
        \Throwable $e,
        UploadedFile $file,
        string $operationId
    ): void {
        $this->logger->error('File validation failed', [
            'operation_id' => $operationId,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($this->isCriticalFailure($e)) {
            $this->handleCriticalFailure($e, $file, $operationId);
        }
    }

    protected function handleCriticalFailure(
        \Throwable $e,
        UploadedFile $file,
        string $operationId
    ): void {
        $this->logger->critical('Critical file validation failure', [
            'operation_id' => $operationId,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->security->quarantineFile($file->path());
        $this->notifySecurityTeam($e, $file, $operationId);
    }

    protected function isCriticalFailure(\Throwable $e): bool
    {
        return $e instanceof SecurityException || 
               $e instanceof MalwareDetectedException ||
               $e instanceof CriticalValidationException;
    }

    protected function notifySecurityTeam(
        \Throwable $e,
        UploadedFile $file,
        string $operationId
    ): void {
        $this->security->triggerSecurityAlert([
            'type' => 'critical_file_validation_failure',
            'operation_id' => $operationId,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'error' => $e->getMessage(),
            'severity' => 'CRITICAL'
        ]);
    }

    public function validateMetadata(array $metadata): void
    {
        $this->validateMetadataStructure($metadata);
        $this->validateMetadataContent($metadata);
        $this->sanitizeMetadata($metadata);
    }

    protected function validateMetadataStructure(array $metadata): void
    {
        $rules = [
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'alt_text' => 'nullable|string|max:255',
            'copyright' => 'nullable|string|max:255',
            'author' => 'nullable|string|max:255',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50'
        ];

        $validator = $this->validator->make($metadata, $rules);

        if ($validator->fails()) {
            throw new ValidationException('Invalid metadata structure');
        }
    }

    protected function validateMetadataContent(array $metadata): void
    {
        foreach ($metadata as $key => $value) {
            if (!$this->isValidMetadataValue($value)) {
                throw new ValidationException("Invalid metadata content for key: {$key}");
            }
        }
    }

    protected function sanitizeMetadata(array &$metadata): void
    {
        array_walk_recursive($metadata, function(&$value) {
            if (is_string($value)) {
                $value = $this->security->sanitizeString($value);
            }
        });
    }

    protected function isValidMetadataValue($value): bool
    {
        if (is_string($value)) {
            return !$this->containsMaliciousContent($value);
        }

        if (is_array($value)) {
            return array_reduce($value, function($carry, $item) {
                return $carry && $this->isValidMetadataValue($item);
            }, true);
        }

        return true;
    }

    protected function containsMaliciousContent(string $value): bool
    {
        return $this->security->containsXss($value) ||
               $this->security->containsSqlInjection($value) ||
               $this->security->containsCommandInjection($value);
    }
}
