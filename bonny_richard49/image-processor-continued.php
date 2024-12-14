<?php

namespace App\Core\CMS\Media\Processors;

class ImageProcessor implements ImageProcessorInterface
{
    private array $config;
    private SecurityService $security;
    private ValidationService $validator;
    private AuditLogger $logger;

    public function process(UploadedFile $file, array $options = []): ProcessedFile
    {
        $operationId = uniqid('img_process_', true);

        try {
            // Validate image
            $this->validateImage($file);

            // Create image instance
            $image = $this->createImage($file);

            // Apply transformations
            $image = $this->applyTransformations($image, $options);

            // Optimize image
            if ($options['optimize'] ?? true) {
                $image = $this->optimizeImage($image, $options);
            }

            // Save processed image
            $result = $this->saveProcessedImage($image, $options);

            // Log success
            $this->logSuccess($file, $result, $operationId);

            return $result;

        } catch (\Throwable $e) {
            $this->handleProcessingFailure($e, $file, $operationId);
            throw $e;
        }
    }

    protected function validateImage(UploadedFile $file): void
    {
        // Check MIME type
        if (!in_array($file->getMimeType(), $this->supportedMimeTypes)) {
            throw new ValidationException('Unsupported image type');
        }

        // Validate image structure
        if (!$this->validator->validateImageStructure($file)) {
            throw new ValidationException('Invalid image structure');
        }

        // Security scan
        $this->security->scanImage($file->path());

        // Validate dimensions
        $dimensions = @getimagesize($file->path());
        if (!$dimensions) {
            throw new ValidationException('Unable to get image dimensions');
        }

        if ($dimensions[0] > $this->config['max_width'] || 
            $dimensions[1] > $this->config['max_height']) {
            throw new ValidationException('Image dimensions exceed limits');
        }
    }

    protected function createImage(UploadedFile $file): Image
    {
        $source = @imagecreatefromstring(file_get_contents($file->path()));
        if (!$source) {
            throw new ProcessingException('Failed to create image resource');
        }

        return new Image($source);
    }

    protected function applyTransformations(Image $image, array $options): Image
    {
        // Resize if needed
        if (isset($options['max_width']) || isset($options['max_height'])) {
            $image = $this->resize($image, [
                'max_width' => $options['max_width'] ?? null,
                'max_height' => $options['max_height'] ?? null,
                'preserve_aspect_ratio' => $options['preserve_aspect_ratio'] ?? true
            ]);
        }

        // Crop if needed
        if (isset($options['crop'])) {
            $image = $this->crop($image, $options['crop']);
        }

        // Rotate if needed
        if (isset($options['rotate'])) {
            $image = $this->rotate($image, $options['rotate']);
        }

        // Apply filters
        if (isset($options['filters'])) {
            $image = $this->applyFilters($image, $options['filters']);
        }

        return $image;
    }

    protected function resize(Image $image, array $options): Image
    {
        $currentWidth = imagesx($image->getResource());
        $currentHeight = imagesy($image->getResource());

        // Calculate new dimensions
        [$newWidth, $newHeight] = $this->calculateDimensions(
            $currentWidth,
            $currentHeight,
            $options['max_width'] ?? null,
            $options['max_height'] ?? null,
            $options['preserve_aspect_ratio'] ?? true
        );

        // Create new image
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        if (!$resized) {
            throw new ProcessingException('Failed to create resized image');
        }

        // Preserve transparency
        $this->preserveTransparency($resized, $image->getResource());

        // Perform resize
        if (!imagecopyresampled(
            $resized,
            $image->getResource(),
            0, 0, 0, 0,
            $newWidth,
            $newHeight,
            $currentWidth,
            $currentHeight
        )) {
            throw new ProcessingException('Failed to resize image');
        }

        return new Image($resized);
    }

    protected function optimizeImage(Image $image, array $options): Image
    {
        // Set quality
        $quality = $options['quality'] ?? $this->config['default_quality'];

        // Apply compression
        if ($options['compress'] ?? true) {
            imagepalettetotruecolor($image->getResource());
            imagealphablending($image->getResource(), true);
            imagesavealpha($image->getResource(), true);
        }

        // Color optimization
        if ($options['optimize_colors'] ?? true) {
            $this->optimizeColors($image);
        }

        // Strip metadata if not needed
        if (!($options['preserve_metadata'] ?? false)) {
            $this->stripMetadata($image);
        }

        return $image;
    }

    protected function saveProcessedImage(Image $image, array $options): ProcessedFile
    {
        $format = $options['format'] ?? 'jpg';
        $quality = $options['quality'] ?? $this->config['default_quality'];
        
        $tempPath = tempnam(sys_get_temp_dir(), 'img_');
        
        switch ($format) {
            case 'jpg':
            case 'jpeg':
                imagejpeg($image->getResource(), $tempPath, $quality);
                break;
            case 'png':
                imagepng($image->getResource(), $tempPath, min(9, (int)(90 - $quality) / 10));
                break;
            case 'webp':
                imagewebp($image->getResource(), $tempPath, $quality);
                break;
            default:
                throw new ProcessingException('Unsupported output format');
        }

        return new ProcessedFile($tempPath, [
            'width' => imagesx($image->getResource()),
            'height' => imagesy($image->getResource()),
            'format' => $format,
            'size' => filesize($tempPath),
            'mime_type' => "image/{$format}"
        ]);
    }

    protected function handleProcessingFailure(
        \Throwable $e,
        UploadedFile $file,
        string $operationId
    ): void {
        $this->logger->error('Image processing failed', [
            'operation_id' => $operationId,
            'filename' => $file->getClientOriginalName(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($this->isCriticalFailure($e)) {
            $this->handleCriticalFailure($e, $file, $operationId);
        }
    }

    protected function isCriticalFailure(\Throwable $e): bool
    {
        return $e instanceof SecurityException ||
               $e instanceof CriticalProcessingException;
    }

    protected function handleCriticalFailure(
        \Throwable $e,
        UploadedFile $file,
        string $operationId
    ): void {
        $this->security->quarantineFile($file->path());
        $this->security->reportThreat([
            'type' => 'critical_image_processing_failure',
            'operation_id' => $operationId,
            'filename' => $file->getClientOriginalName(),
            'error' => $e->getMessage(),
            'severity' => 'CRITICAL'
        ]);
    }

    protected function logSuccess(
        UploadedFile $file,
        ProcessedFile $result,
        string $operationId
    ): void {
        $this->logger->info('Image processed successfully', [
            'operation_id' => $operationId,
            'original_file' => $file->getClientOriginalName(),
            'processed_size' => $result->size,
            'dimensions' => [
                'width' => $result->width,
                'height' => $result->height
            ]
        ]);
    }
}
