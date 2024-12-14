<?php

namespace App\Core\CMS\Media;

class MediaProcessor implements MediaProcessorInterface
{
    private ImageProcessor $imageProcessor;
    private DocumentProcessor $documentProcessor;
    private VideoProcessor $videoProcessor;
    private SecurityService $security;
    private array $config;

    public function processUpload(
        UploadedFile $file, 
        array $options = []
    ): ProcessedFile {
        $operationId = uniqid('process_', true);

        try {
            // Initial security scan
            $this->security->scanFile($file);

            // Process based on type
            $processed = $this->processFileByType($file, $options);

            // Final security check
            $this->security->validateProcessedFile($processed);

            return $processed;

        } catch (\Throwable $e) {
            $this->handleProcessingFailure($e, $file, $operationId);
            throw $e;
        }
    }

    protected function processFileByType(
        UploadedFile $file, 
        array $options
    ): ProcessedFile {
        $mimeType = $file->getMimeType();

        if (str_starts_with($mimeType, 'image/')) {
            return $this->processImage($file, $options);
        }

        if (str_starts_with($mimeType, 'video/')) {
            return $this->processVideo($file, $options);
        }

        if (in_array($mimeType, ['application/pdf', 'application/msword'])) {
            return $this->processDocument($file, $options);
        }

        // Default processing for other types
        return $this->processGenericFile($file, $options);
    }

    protected function processImage(UploadedFile $file, array $options): ProcessedFile
    {
        // Process main image
        $processed = $this->imageProcessor->process($file, [
            'max_width' => $options['max_width'] ?? $this->config['image_max_width'],
            'max_height' => $options['max_height'] ?? $this->config['image_max_height'],
            'optimize' => $options['optimize'] ?? true,
            'preserve_quality' => $options['preserve_quality'] ?? true
        ]);

        // Generate variants if needed
        if ($options['generate_variants'] ?? false) {
            $processed->variants = $this->generateImageVariants($file, $options);
        }

        // Extract metadata
        $processed->metadata = $this->extractImageMetadata($file);

        return $processed;
    }

    protected function processVideo(UploadedFile $file, array $options): ProcessedFile 
    {
        // Process video file
        $processed = $this->videoProcessor->process($file, [
            'max_duration' => $options['max_duration'] ?? $this->config['video_max_duration'],
            'max_size' => $options['max_size'] ?? $this->config['video_max_size'],
            'format' => $options['format'] ?? $this->config['video_format'],
            'generate_thumbnail' => $options['generate_thumbnail'] ?? true
        ]);

        // Generate thumbnail if needed
        if ($options['generate_thumbnail'] ?? true) {
            $processed->thumbnail = $this->generateVideoThumbnail($file);
        }

        // Extract metadata
        $processed->metadata = $this->extractVideoMetadata($file);

        return $processed;
    }

    protected function processDocument(UploadedFile $file, array $options): ProcessedFile
    {
        // Process document
        $processed = $this->documentProcessor->process($file, [
            'convert_to_pdf' => $options['convert_to_pdf'] ?? false,
            'extract_text' => $options['extract_text'] ?? true,
            'sanitize' => $options['sanitize'] ?? true
        ]);

        // Generate preview if needed
        if ($options['generate_preview'] ?? true) {
            $processed->preview = $this->generateDocumentPreview($file);
        }

        // Extract metadata
        $processed->metadata = $this->extractDocumentMetadata($file);

        return $processed;
    }

    protected function generateImageVariants(UploadedFile $file, array $options): array
    {
        $variants = [];

        foreach ($this->config['image_variants'] as $name => $config) {
            $variants[$name] = $this->imageProcessor->createVariant($file, array_merge(
                $config,
                $options[$name] ?? []
            ));
        }

        return $variants;
    }

    protected function generateVideoThumbnail(UploadedFile $file): ProcessedFile
    {
        return $this->videoProcessor->generateThumbnail($file, [
            'time' => '00:00:01',
            'width' => $this->config['video_thumbnail_width'],
            'height' => $this->config['video_thumbnail_height']
        ]);
    }

    protected function generateDocumentPreview(UploadedFile $file): ProcessedFile
    {
        return $this->documentProcessor->generatePreview($file, [
            'pages' => 1,
            'width' => $this->config['document_preview_width'],
            'height' => $this->config['document_preview_height']
        ]);
    }

    protected function extractImageMetadata(UploadedFile $file): array 
    {
        $metadata = [];

        try {
            $exif = @exif_read_data($file->path());
            if ($exif) {
                $metadata['exif'] = $this->sanitizeExifData($exif);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to extract EXIF data', [
                'file' => $file->getClientOriginalName(),
                'error' => $e->getMessage()
            ]);
        }

        return array_merge($metadata, [
            'dimensions' => $this->imageProcessor->getDimensions($file),
            'color_space' => $this->imageProcessor->getColorSpace($file),
            'file_size' => $file->getSize()
        ]);
    }

    protected function extractVideoMetadata(UploadedFile $file): array
    {
        return [
            'duration' => $this->videoProcessor->getDuration($file),
            'dimensions' => $this->videoProcessor->getDimensions($file),
            'codec' => $this->videoProcessor->getCodec($file),
            'bitrate' => $this->videoProcessor->getBitrate($file),
            'file_size' => $file->getSize()
        ];
    }

    protected function extractDocumentMetadata(UploadedFile $file): array
    {
        return [
            'pages' => $this->documentProcessor->getPageCount($file),
            'author' => $this->documentProcessor->getAuthor($file),
            'created_at' => $this->documentProcessor->getCreationDate($file),
            'modified_at' => $this->documentProcessor->getModificationDate($file),
            'file_size' => $file->getSize()
        ];
    }

    protected function sanitizeExifData(array $exif): array
    {
        $allowed = [
            'Make', 'Model', 'DateTime', 'ExposureTime', 'FNumber',
            'ISOSpeedRatings', 'FocalLength', 'WhiteBalance'
        ];

        return array_intersect_key($exif, array_flip($allowed));
    }

    protected function handleProcessingFailure(
        \Throwable $e, 
        UploadedFile $file,
        string $operationId
    ): void {
        $this->logger->error('File processing failed', [
            'operation_id' => $operationId,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'error' => $e->getMessage()
        ]);

        if ($this->isSecurityFailure($e)) {
            $this->handleSecurityFailure($e, $file, $operationId);
        }
    }

    protected function isSecurityFailure(\Throwable $e): bool
    {
        return $e instanceof SecurityException ||
               $e instanceof MalwareDetectedException;
    }

    protected function handleSecurityFailure(
        \Throwable $e,
        UploadedFile $file,
        string $operationId
    ): void {
        $this->security->quarantineFile($file->path());
        $this->security->reportThreat([
            'type' => 'media_processing_security_failure',
            'operation_id' => $operationId,
            'filename' => $file->getClientOriginalName(),
            'error' => $e->getMessage(),
            'severity' => 'CRITICAL'
        ]);
    }
}
