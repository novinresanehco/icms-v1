<?php

namespace App\Core\DataPortability\Contracts;

interface DataExportInterface
{
    public function export(array $entities, string $format = 'json'): ExportResult;
    public function createExportJob(array $entities, string $format = 'json'): string;
    public function getExportStatus(string $jobId): ExportStatus;
    public function cancelExport(string $jobId): bool;
}

interface DataImportInterface
{
    public function import(string $source, array $options = []): ImportResult;
    public function validate(string $source): ValidationResult;
    public function createImportJob(string $source, array $options = []): string;
    public function getImportStatus(string $jobId): ImportStatus;
    public function cancelImport(string $jobId): bool;
}

namespace App\Core\DataPortability\Services;

class DataExportService implements DataExportInterface
{
    protected EntityManager $entityManager;
    protected FormatManager $formatManager;
    protected JobManager $jobManager;
    protected ValidationService $validator;
    protected EventDispatcher $events;

    public function __construct(
        EntityManager $entityManager,
        FormatManager $formatManager,
        JobManager $jobManager,
        ValidationService $validator,
        EventDispatcher $events
    ) {
        $this->entityManager = $entityManager;
        $this->formatManager = $formatManager;
        $this->jobManager = $jobManager;
        $this->validator = $validator;
        $this->events = $events;
    }

    public function export(array $entities, string $format = 'json'): ExportResult
    {
        try {
            // Validate entities
            $this->validator->validateEntities($entities);

            // Get formatter
            $formatter = $this->formatManager->getFormatter($format);

            // Collect data
            $data = $this->collectData($entities);

            // Format data
            $formattedData = $formatter->format($data);

            // Create result
            $result = new ExportResult([
                'data' => $formattedData,
                'format' => $format,
                'metadata' => $this->generateMetadata($entities)
            ]);

            // Dispatch event
            $this->events->dispatch(new DataExported($result));

            return $result;
        } catch (\Exception $e) {
            throw new ExportException("Export failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function createExportJob(array $entities, string $format = 'json'): string
    {
        // Create and queue export job
        $job = new ExportJob([
            'entities' => $entities,
            'format' => $format
        ]);

        return $this->jobManager->queue($job);
    }

    public function getExportStatus(string $jobId): ExportStatus
    {
        return $this->jobManager->getStatus($jobId);
    }

    public function cancelExport(string $jobId): bool
    {
        return $this->jobManager->cancel($jobId);
    }

    protected function collectData(array $entities): array
    {
        $data = [];

        foreach ($entities as $entity) {
            $entityData = $this->entityManager->extract($entity);
            $data[$entity['type']][] = $entityData;
        }

        return $data;
    }

    protected function generateMetadata(array $entities): array
    {
        return [
            'timestamp' => now(),
            'entity_count' => count($entities),
            'types' => array_unique(array_column($entities, 'type')),
            'version' => config('app.version')
        ];
    }
}

class DataImportService implements DataImportInterface
{
    protected EntityManager $entityManager;
    protected FormatManager $formatManager;
    protected JobManager $jobManager;
    protected ValidationService $validator;
    protected ConflictResolver $conflictResolver;
    protected EventDispatcher $events;

    public function import(string $source, array $options = []): ImportResult
    {
        try {
            // Validate source
            $validation = $this->validate($source);
            if (!$validation->isValid()) {
                throw new ValidationException($validation->getErrors());
            }

            // Parse source data
            $data = $this->parseSource($source);

            // Start transaction
            DB::beginTransaction();

            try {
                // Process each entity type
                $results = [];
                foreach ($data as $type => $entities) {
                    $results[$type] = $this->importEntities($type, $entities, $options);
                }

                // Commit transaction
                DB::commit();

                // Create result
                $result = new ImportResult([
                    'success' => true,
                    'results' => $results,
                    'metadata' => $this->generateMetadata($data)
                ]);

                // Dispatch event
                $this->events->dispatch(new DataImported($result));

                return $result;
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            throw new ImportException("Import failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function validate(string $source): ValidationResult
    {
        return $this->validator->validateSource($source);
    }

    public function createImportJob(string $source, array $options = []): string
    {
        // Create and queue import job
        $job = new ImportJob([
            'source' => $source,
            'options' => $options
        ]);

        return $this->jobManager->queue($job);
    }

    public function getImportStatus(string $jobId): ImportStatus
    {
        return $this->jobManager->getStatus($jobId);
    }

    public function cancelImport(string $jobId): bool
    {
        return $this->jobManager->cancel($jobId);
    }

    protected function parseSource(string $source): array
    {
        $extension = pathinfo($source, PATHINFO_EXTENSION);
        $parser = $this->formatManager->getParser($extension);
        return $parser->parse($source);
    }

    protected function importEntities(string $type, array $entities, array $options): array
    {
        $results = [
            'total' => count($entities),
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0
        ];

        foreach ($entities as $entity) {
            try {
                // Check for existing entity
                $existing = $this->entityManager->findExisting($type, $entity);

                if ($existing) {
                    if ($options['skip_existing'] ?? false) {
                        $results['skipped']++;
                        continue;
                    }

                    // Resolve conflicts
                    $resolved = $this->conflictResolver->resolve($existing, $entity, $options);
                    
                    // Update entity
                    $this->entityManager->update($existing, $resolved);
                    $results['updated']++;
                } else {
                    // Create new entity
                    $this->entityManager->create($type, $entity);
                    $results['created']++;
                }
            } catch (\Exception $e) {
                $results['failed']++;
                if (!($options['continue_on_error'] ?? false)) {
                    throw $e;
                }
            }
        }

        return $results;
    }
}

namespace App\Core\DataPortability\Services;

class FormatManager
{
    protected array $formatters = [];
    protected array $parsers = [];

    public function registerFormatter(string $format, DataFormatterInterface $formatter): void
    {
        $this->formatters[$format] = $formatter;
    }

    public function registerParser(string $format, DataParserInterface $parser): void
    {
        $this->parsers[$format] = $parser;
    }

    public function getFormatter(string $format): DataFormatterInterface
    {
        if (!isset($this->formatters[$format])) {
            throw new UnsupportedFormatException("Format not supported: {$format}");
        }

        return $this->formatters[$format];
    }

    public function getParser(string $format): DataParserInterface
    {
        if (!isset($this->parsers[$format])) {
            throw new UnsupportedFormatException("Parser not found for format: {$format}");
        }

        return $this->parsers[$format];
    }
}

namespace App\Core\DataPortability\Formatters;

class JsonFormatter implements DataFormatterInterface
{
    public function format(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT);
    }

    public function supports(string $format): bool
    {
        return $format === 'json';
    }
}

class XmlFormatter implements DataFormatterInterface
{
    protected XMLWriter $writer;

    public function format(array $data): string
    {
        $this->writer = new XMLWriter();
        $this->writer->openMemory();
        $this->writer->setIndent(true);
        $this->writer->startDocument('1.0', 'UTF-8');
        
        $this->writer->startElement('export');
        $this->arrayToXml($data);
        $this->writer->endElement();

        return $this->writer->outputMemory();
    }

    public function supports(string $format): bool
    {
        return $format === 'xml';
    }

    protected function arrayToXml(array $data, string $parentKey = ''): void
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $this->writer->startElement(is_numeric($key) ? $parentKey : $key);
                $this->arrayToXml($value, is_numeric($key) ? $parentKey : $key);
                $this->writer->endElement();
            } else {
                $this->writer->writeElement(is_numeric($key) ? 'item' : $key, (string)$value);
            }
        }
    }
}

class CsvFormatter implements DataFormatterInterface
{
    public function format(array $data): string
    {
        $output = fopen('php://temp', 'r+');
        
        foreach ($data as $type => $entities) {
            // Add type header
            fputcsv($output, [$type]);
            
            // Add headers
            if (!empty($entities)) {
                fputcsv($output, array_keys($entities[0]));
                
                // Add data
                foreach ($entities as $entity) {
                    fputcsv($output, $entity);
                }
                
                // Add blank line between types
                fputcsv($output, []);
            }
        }

        rewind($output);
        $content = stream_get_contents($output);
        fclose($output);

        return $content;
    }

    public function supports(string $format): bool
    {
        return $format === 'csv';
    }
}
