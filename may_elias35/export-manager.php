<?php

namespace App\Core\Audit;

class AuditExportManager
{
    private DataFormatter $formatter;
    private StorageManager $storage;
    private CompressionService $compression;
    private EncryptionService $encryption;
    private JobDispatcher $jobDispatcher;
    private MetricsCollector $metrics;

    public function __construct(
        DataFormatter $formatter,
        StorageManager $storage,
        CompressionService $compression,
        EncryptionService $encryption,
        JobDispatcher $jobDispatcher,
        MetricsCollector $metrics
    ) {
        $this->formatter = $formatter;
        $this->storage = $storage;
        $this->compression = $compression;
        $this->encryption = $encryption;
        $this->jobDispatcher = $jobDispatcher;
        $this->metrics = $metrics;
    }

    public function export(ExportRequest $request): ExportResult
    {
        $startTime = microtime(true);

        try {
            // Validate request
            $this->validateRequest($request);

            // Check size and determine export strategy
            if ($this->shouldProcessAsync($request)) {
                return $this->processAsyncExport($request);
            }

            // Process synchronous export
            return $this->processSyncExport($request);

        } catch (\Exception $e) {
            $this->handleExportError($e, $request);
            throw new ExportException(
                "Export failed: {$e->getMessage()}",
                0,
                $e
            );
        } finally {
            $this->recordMetrics($request, microtime(true) - $startTime);
        }
    }

    protected function processSyncExport(ExportRequest $request): ExportResult
    {
        // Fetch data
        $data = $this->fetchData($request);

        // Format data
        $formatted = $this->formatData($data, $request->getFormat());

        // Apply transformations
        $transformed = $this->applyTransformations($formatted, $request->getTransformations());

        // Process file
        $file = $this->processFile($transformed, $request);

        // Store file
        $storagePath = $this->storeFile($file, $request);

        return new ExportResult(
            true,
            $storagePath,
            $this->generateMetadata($request, $data)
        );
    }

    protected function processAsyncExport(ExportRequest $request): ExportResult
    {
        // Create export job
        $job = new ExportJob($request);

        // Dispatch job
        $jobId = $this->jobDispatcher->dispatch($job);

        return new ExportResult(
            false,
            null,
            ['job_id' => $jobId],
            ExportStatus::PROCESSING
        );
    }

    protected function fetchData(ExportRequest $request): array
    {
        $query = $this->buildQuery($request);
        return $query->get();
    }

    protected function formatData(array $data, string $format): string
    {
        return match($format) {
            'json' => $this->formatter->toJson($data),
            'csv' => $this->formatter->toCsv($data),
            'xml' => $this->formatter->toXml($data),
            'xlsx' => $this->formatter->toExcel($data),
            default => throw new UnsupportedFormatException("Unsupported format: {$format}")
        };
    }

    protected function applyTransformations(string $data, array $transformations): string
    {
        foreach ($transformations as $transformation) {
            $data = $transformation->apply($data);
        }

        return $data;
    }

    protected function processFile(string $data, ExportRequest $request): File
    {
        // Create temporary file
        $file = new File(
            tempnam(sys_get_temp_dir(), 'audit_export_'),
            $request->getFormat()
        );

        // Write data
        $file->write($data);

        // Compress if needed
        if ($request->shouldCompress()) {
            $file = $this->compression->compress($file);
        }

        // Encrypt if needed
        if ($request->shouldEncrypt()) {
            $file = $this->encryption->encrypt($file);
        }

        return $file;
    }

    protected function storeFile(File $file, ExportRequest $request): string
    {
        $path = $this->generateStoragePath($request);
        
        return $this->storage->store(
            $file,
            $path,
            $this->getStorageOptions($request)
        );
    }

    protected function shouldProcessAsync(ExportRequest $request): bool
    {
        return $request->getEstimatedSize() > config('audit.export.async_threshold')
            || $request->preferAsync();
    }

    protected function generateStoragePath(ExportRequest $request): string
    {
        return sprintf(
            'exports/%s/%s.%s',
            date('Y/m/d'),
            Str::uuid(),
            $request->getFormat()
        );
    }

    protected function getStorageOptions(ExportRequest $request): array
    {
        return [
            'visibility' => $request->getVisibility(),
            'metadata' => [
                'format' => $request->getFormat(),
                'compressed' => $request->shouldCompress(),
                'encrypted' => $request->shouldEncrypt(),
                'timestamp' => now(),
                'requester' => $request->getRequesterId()
            ]
        ];
    }

    protected function generateMetadata(ExportRequest $request, array $data): array
    {
        return [
            'record_count' => count($data),
            'format' => $request->getFormat(),
            'filters' => $request->getFilters(),
            'timestamp' => now(),
            'compressed' => $request->shouldCompress(),
            'encrypted' => $request->shouldEncrypt()
        ];
    }

    protected function validateRequest(ExportRequest $request): void
    {
        $validator = new ExportRequestValidator();
        
        if (!$validator->validate($request)) {
            throw new InvalidExportRequestException(
                'Invalid export request: ' . implode(', ', $validator->getErrors())
            );
        }
    }

    protected function recordMetrics(ExportRequest $request, float $duration): void
    {
        $this->metrics->record([
            'export_duration' => $duration,
            'export_format' => $request->getFormat(),
            'export_size' => $request->getEstimatedSize(),
            'export_compressed' => $request->shouldCompress(),
            'export_encrypted' => $request->shouldEncrypt()
        ]);
    }
}
