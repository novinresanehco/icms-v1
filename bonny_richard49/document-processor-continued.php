<?php

namespace App\Core\CMS\Media\Processors;

class DocumentProcessor implements DocumentProcessorInterface 
{
    protected function handleProcessingFailure(
        \Throwable $e,
        UploadedFile $file,
        string $operationId
    ): void {
        $this->logger->error('Document processing failed', [
            'operation_id' => $operationId,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($this->isCriticalFailure($e)) {
            $this->handleCriticalFailure($e, $file, $operationId);
        }

        if ($this->shouldQuarantine($e)) {
            $this->security->quarantineFile($file->path());
        }
    }

    protected function isCriticalFailure(\Throwable $e): bool
    {
        return $e instanceof SecurityException ||
               $e instanceof MalwareDetectedException ||
               $e instanceof CriticalProcessingException;
    }

    protected function shouldQuarantine(\Throwable $e): bool
    {
        return $e instanceof SecurityException ||
               $e instanceof MalwareDetectedException ||
               $e instanceof SuspiciousContentException;
    }

    protected function handleCriticalFailure(
        \Throwable $e,
        UploadedFile $file,
        string $operationId
    ): void {
        $this->logger->critical('Critical document processing failure', [
            'operation_id' => $operationId,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->security->reportSecurityThreat([
            'type' => 'critical_document_processing_failure',
            'operation_id' => $operationId,
            'filename' => $file->getClientOriginalName(),
            'error' => $e->getMessage(),
            'severity' => 'CRITICAL',
            'threat_type' => $this->determineThreatType($e)
        ]);

        $this->notifySecurityTeam($e, $file, $operationId);
    }

    protected function determineThreatType(\Throwable $e): string
    {
        if ($e instanceof MalwareDetectedException) {
            return 'malware_detected';
        }
        
        if ($e instanceof SuspiciousContentException) {
            return 'suspicious_content';
        }
        
        if ($e instanceof SecurityException) {
            return 'security_violation';
        }

        return 'unknown_threat';
    }

    protected function notifySecurityTeam(
        \Throwable $e,
        UploadedFile $file,
        string $operationId
    ): void {
        foreach ($this->config['security_notification_channels'] as $channel) {
            $channel->notifySecurityTeam([
                'type' => 'critical_document_security_alert',
                'operation_id' => $operationId,
                'filename' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'severity' => 'CRITICAL',
                'threat_type' => $this->determineThreatType($e),
                'timestamp' => time()
            ]);
        }
    }

    protected function logSuccess(
        UploadedFile $file,
        ProcessedFile $processed,
        string $operationId
    ): void {
        $this->logger->info('Document processed successfully', [
            'operation_id' => $operationId,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'original_size' => $file->getSize(),
            'processed_size' => $processed->getSize(),
            'metadata' => [
                'pages' => $processed->metadata['pages'] ?? null,
                'created_at' => $processed->metadata['created_at'] ?? null,
                'modified_at' => $processed->metadata['modified_at'] ?? null
            ],
            'has_preview' => isset($processed->preview),
            'processing_time' => microtime(true) - $this->processingStartTime
        ]);
    }

    protected function getDocumentTitle(ProcessedFile $file): ?string
    {
        try {
            return match($file->getMimeType()) {
                'application/pdf' => $this->pdfProcessor->extractTitle($file),
                default => $this->officeProcessor->extractTitle($file)
            };
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to extract document title', [
                'error' => $e->getMessage(),
                'file' => $file->getFileName()
            ]);
            return null;
        }
    }

    protected function getDocumentAuthor(ProcessedFile $file): ?string 
    {
        try {
            return match($file->getMimeType()) {
                'application/pdf' => $this->pdfProcessor->extractAuthor($file),
                default => $this->officeProcessor->extractAuthor($file)
            };
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to extract document author', [
                'error' => $e->getMessage(),
                'file' => $file->getFileName()
            ]);
            return null;
        }
    }

    protected function getDocumentPages(ProcessedFile $file): ?int
    {
        try {
            return match($file->getMimeType()) {
                'application/pdf' => $this->pdfProcessor->getPageCount($file),
                default => $this->officeProcessor->getPageCount($file)
            };
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to get document page count', [
                'error' => $e->getMessage(),
                'file' => $file->getFileName()
            ]);
            return null;
        }
    }
}
