<?php

namespace App\Core\Media;

final class ProcessorService
{
    private array $processors;
    private ValidationService $validator;
    private SecurityService $security;
    private AuditService $audit;

    public function __construct(
        array $processors,
        ValidationService $validator,
        SecurityService $security,
        AuditService $audit
    ) {
        $this->processors = $processors;
        $this->validator = $validator;
        $this->security = $security;
        $this->audit = $audit;
    }

    public function process(UploadedFile $file, array $options = []): ProcessedFile
    {
        // Get processor
        $processor = $this->getProcessor($file->getMimeType());
        
        // Initialize processing
        $processingId = $this->audit->startProcessing($file);
        
        try {
            // Pre-process validation
            $this->validator->validateProcessing($file, $options);
            
            // Process with security checks
            $processed = $this->security->executeSecure(function() use ($processor, $file, $options) {
                return $processor->process($file, $options);
            });
            
            // Verify processed file
            $this->verifyProcessedFile($processed);
            
            // Audit trail
            $this->audit->completeProcessing($processingId, $processed);
            
            return $processed;
            
        } catch (\Throwable $e) {
            $this->audit->failProcessing($processingId, $e);
            throw $e;
        }
    }

    public function createThumbnails(Media $media, array $options = []): array
    {
        if (!$this->canCreateThumbnails($media)) {
            throw new UnsupportedMediaTypeException('Cannot create thumbnails for this media type');
        }

        // Get image processor
        $processor = $this->getProcessor('image');
        
        // Initialize thumbnail creation
        $processingId = $this->audit->startThumbnailCreation($media);
        
        try {
            $thumbnails = $this->security->executeSecure(function() use ($processor, $media, $options) {
                return $processor->createThumbnails(
                    $media->path,
                    $this->getThumbnailConfig($options)
                );
            });
            
            // Verify thumbnails
            $this->verifyThumbnails($thumbnails);
            
            // Audit trail
            $this->audit->completeThumbnailCreation($processingId, $thumbnails);
            
            return $thumbnails;
            
        } catch (\Throwable $e) {
            $this->audit->failThumbnailCreation($processingId, $e);
            throw $e;
        }
    }

    private function getProcessor(string $mimeType): ProcessorInterface
    {
        foreach ($this->processors as $type => $processor) {
            if ($processor->supports($mimeType)) {
                return $processor;
            }
        }
        
        throw new UnsupportedMediaTypeException("No processor for mime type: {$mimeType}");
    }

    private function verifyProcessedFile(ProcessedFile $file): void
    {
        if (!$this->validator->validateProcessedFile($file)) {
            throw new ProcessingException('Processed file validation failed');
        }
    }

    private function verifyThumbnails(array $thumbnails): void
    {
        foreach ($thumbnails as $thumbnail) {
            if (!$this->validator->validateThumbnail($thumbnail)) {
                throw new ProcessingException('Thumbnail validation failed');
            }
        }
    }

    private function getThumbnailConfig(array $options): array
    {
        return array_merge(
            config('media.thumbnails', []),
            $options
        );
    }

    private function canCreateThumbnails(Media $media): bool
    {
        return str_starts_with($media->mime_type, 'image/');
    }
}
