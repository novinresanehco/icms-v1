<?php

namespace App\Core\CMS\Media\Processors;

use setasign\Fpdi\Fpdi;
use Spatie\PdfToImage\Pdf;

class PdfProcessor implements PdfProcessorInterface
{
    private SecurityService $security;
    private ValidationService $validator;
    private array $config;
    private array $state = [];

    public function process(
        ProcessedFile $file, 
        array $options = []
    ): ProcessedFile {
        $operationId = uniqid('pdf_process_', true);
        $this->state['processing_id'] = $operationId;

        try {
            // Validate PDF
            $this->validatePdf($file);

            // Load PDF
            $pdf = new Fpdi();
            $pageCount = $pdf->setSourceFile($file->getPath());

            // Apply processing options
            $processed = $this->applyProcessingOptions($pdf, $pageCount, $options);

            // Generate preview if needed
            if ($options['generate_preview'] ?? true) {
                $processed->preview = $this->generatePreview($file, $options);
            }

            // Extract text if needed
            if ($options['extract_text'] ?? true) {
                $processed->text = $this->extractText($file);
            }

            // Log success
            $this->logSuccess($file, $processed, $operationId);

            return $processed;

        } catch (\Throwable $e) {
            $this->handleProcessingFailure($e, $file, $operationId);
            throw $e;
        } finally {
            $this->cleanup();
        }
    }

    protected function validatePdf(ProcessedFile $file): void
    {
        // Check PDF version compatibility
        if (!$this->validator->validatePdfVersion($file)) {
            throw new ValidationException('Unsupported PDF version');
        }

        // Validate PDF structure
        if (!$this->validator->validatePdfStructure($file)) {
            throw new ValidationException('Invalid PDF structure');
        }

        // Check for malicious content
        if ($this->security->scanForMaliciousContent($file)) {
            throw new SecurityException('Malicious content detected in PDF');
        }

        // Check for encrypted content
        if ($this->isPdfEncrypted($file)) {
            throw new ValidationException('Encrypted PDFs not supported');
        }

        // Validate page count
        $pageCount = $this->getPageCount($file);
        if ($pageCount > $this->config['max_pages']) {
            throw new ValidationException('PDF exceeds maximum page limit');
        }
    }

    protected function applyProcessingOptions(
        Fpdi $pdf, 
        int $pageCount, 
        array $options
    ): ProcessedFile {
        $tempPath = tempnam(sys_get_temp_dir(), 'pdf_');

        // Process each page
        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
            $templateId = $pdf->importPage($pageNo);
            $pdf->AddPage();
            
            // Apply page options
            $this->applyPageOptions($pdf, $templateId, $options);

            // Apply watermark if needed
            if ($options['watermark'] ?? false) {
                $this->applyWatermark($pdf, $options['watermark']);
            }
        }

        // Save processed PDF
        $pdf->Output($tempPath, 'F');

        return new ProcessedFile($tempPath, [
            'pages' => $pageCount,
            'size' => filesize($tempPath),
            'mime_type' => 'application/pdf'
        ]);
    }

    protected function applyPageOptions(
        Fpdi $pdf,
        int $templateId,
        array $options
    ): void {
        // Apply page size and orientation
        $size = $options['page_size'] ?? 'A4';
        $orientation = $options['orientation'] ?? 'P';
        $pdf->useTemplate($templateId, ['size' => $size, 'orientation' => $orientation]);

        // Apply compression if needed
        if ($options['compress'] ?? true) {
            $pdf->setCompression(true);
        }

        // Set other PDF options
        $this->setPdfOptions($pdf, $options);
    }

    protected function generatePreview(
        ProcessedFile $file,
        array $options
    ): ProcessedFile {
        $preview = new Pdf($file->getPath());

        // Configure preview options
        $preview->setPage($options['preview_page'] ?? 1);
        $preview->setOutputFormat('png');
        $preview->setResolution($options['resolution'] ?? 150);

        // Generate preview image
        $previewPath = tempnam(sys_get_temp_dir(), 'preview_');
        $preview->saveImage($previewPath);

        return new ProcessedFile($previewPath, [
            'mime_type' => 'image/png',
            'size' => filesize($previewPath)
        ]);
    }

    protected function extractText(ProcessedFile $file): string
    {
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($file->getPath());
            
            $text = $pdf->getText();
            
            // Validate extracted text
            $this->validateExtractedText($text);
            
            return $text;
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to extract PDF text', [
                'error' => $e->getMessage(),
                'file' => $file->getFileName()
            ]);
            return '';
        }
    }

    protected function validateExtractedText(string $text): void
    {
        // Check for malicious content in text
        if ($this->security->containsMaliciousContent($text)) {
            throw new SecurityException('Malicious content detected in PDF text');
        }

        // Validate text length
        if (strlen($text) > $this->config['max_text_length']) {
            throw new ValidationException('Extracted text exceeds maximum length');
        }
    }

    protected function isPdfEncrypted(ProcessedFile $file): bool
    {
        // Check for encryption using low-level PDF parsing
        $content = file_get_contents($file->getPath());
        return strpos($content, '/Encrypt') !== false;
    }

    protected function handleProcessingFailure(
        \Throwable $e,
        ProcessedFile $file,
        string $operationId
    ): void {
        $this->logger->error('PDF processing failed', [
            'operation_id' => $operationId,
            'filename' => $file->getFileName(),
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
        ProcessedFile $file,
        string $operationId
    ): void {
        $this->security->quarantineFile($file->getPath());
        $this->security->reportThreat([
            'type' => 'critical_pdf_processing_failure',
            'operation_id' => $operationId,
            'filename' => $file->getFileName(),
            'error' => $e->getMessage(),
            'severity' => 'CRITICAL'
        ]);
    }

    protected function cleanup(): void
    {
        // Clean up any temporary files
        foreach ($this->state['temp_files'] ?? [] as $tempFile) {
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
        }

        // Reset state
        $this->state = [];
    }
}
