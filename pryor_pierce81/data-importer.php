<?php

namespace App\Core\Import;

class DataImporter
{
    private array $parsers = [];
    private array $validators = [];
    private ImportJobRepository $repository;
    private BatchProcessor $batchProcessor;

    public function import(ImportRequest $request): ImportResult
    {
        $job = $this->repository->create($request);

        try {
            $parser = $this->getParser($request->getFormat());
            $validator = $this->getValidator($request->getType());

            $data = $parser->parse($request->getFile(), $request->getOptions());
            $validationResult = $validator->validate($data);

            if (!$validationResult->isValid()) {
                throw new ImportValidationException($validationResult->getErrors());
            }

            $result = $this->batchProcessor->process($data, $request->getHandler());
            $this->repository->markAsCompleted($job, $result);

            return new ImportResult($job, $result);

        } catch (\Exception $e) {
            $this->repository->markAsFailed($job, $e->getMessage());
            throw new ImportException("Import failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function registerParser(string $format, DataParser $parser): void
    {
        $this->parsers[$format] = $parser;
    }

    public function registerValidator(string $type, DataValidator $validator): void
    {
        $this->validators[$type] = $validator;
    }

    private function getParser(string $format): DataParser
    {
        if (!isset($this->parsers[$format])) {
            throw new ImportException("Unsupported format: {$format}");
        }
        return $this->parsers[$format];
    }

    private function getValidator(string $type): DataValidator
    {
        if (!isset($this->validators[$type])) {
            throw new ImportException("Unsupported type: {$type}");
        }
        return $this->validators[$type];
    }
}

class CsvParser implements DataParser
{
    public function parse($file, array $options = []): array
    {
        $data = [];
        $handle = fopen($file, 'r');
        $headers = $options['headers'] ?? fgetcsv($handle);

        while (($row = fgetcsv($handle)) !== false) {
            $data[] = array_combine($headers, $row);
        }

        fclose($handle);
        return $data;
    }
}

class ExcelParser implements DataParser
{
    public function parse($file, array $options = []): array
    {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
        $worksheet = $spreadsheet->getActiveSheet();
        $data = [];
        $headers = [];

        foreach ($worksheet->getRowIterator() as $row) {
            $rowData = [];
            foreach ($row->getCellIterator() as $cell) {
                $rowData[] = $cell->getValue();
            }

            if (empty($headers)) {
                $headers = $rowData;
            } else {
                $data[] = array_combine($headers, $rowData);
            }
        }

        return $data;
    }
}

class BatchProcessor
{
    private int $batchSize;

    public function __construct(int $batchSize = 1000)
    {
        $this->batchSize = $batchSize;
    }

    public function process(array $data, callable $handler): ProcessingResult
    {
        $processed = 0;
        $failed = 0;
        $errors = [];

        foreach (array_chunk($data, $this->batchSize) as $batch) {
            try {
                $result = $handler($batch);
                $processed += count($batch);
            } catch (\Exception $e) {
                $failed += count($batch);
                $errors[] = $e->getMessage();
            }
        }

        return new ProcessingResult($processed, $failed, $errors);
    }
}

class ProcessingResult
{
    private int $processed;
    private int $failed;
    private array $errors;

    public function __construct(int $processed, int $failed, array $errors = [])
    {
        $this->processed = $processed;
        $this->failed = $failed;
        $this->errors = $errors;
    }

    public function getProcessedCount(): int
    {
        return $this->processed;
    }

    public function getFailedCount(): int
    {
        return $this->failed;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function isSuccessful(): bool
    {
        return $this->failed === 0;
    }
}

class ImportRequest
{
    private $file;
    private string $format;
    private string $type;
    private array $options;
    private $handler;

    public function __construct($file, string $format, string $type, callable $handler, array $options = [])
    {
        $this->file = $file;
        $this->format = $format;
        $this->type = $type;
        $this->handler = $handler;
        $this->options = $options;
    }

    public function getFile()
    {
        return $this->file;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getHandler(): callable
    {
        return $this->handler;
    }
}

class ImportResult
{
    private ImportJob $job;
    private ProcessingResult $result;

    public function __construct(ImportJob $job, ProcessingResult $result)
    {
        $this->job = $job;
        $this->result = $result;
    }

    public function getJob(): ImportJob
    {
        return $this->job;
    }

    public function getResult(): ProcessingResult
    {
        return $this->result;
    }
}

interface DataParser
{
    public function parse($file, array $options = []): array;
}

interface DataValidator
{
    public function validate(array $data): ValidationResult;
}

class ValidationResult
{
    private bool $valid;
    private array $errors;

    public function __construct(bool $valid, array $errors = [])
    {
        $this->valid = $valid;
        $this->errors = $errors;
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}

class ImportJob
{
    private int $id;
    private ImportRequest $request;

    public function __construct(int $id, ImportRequest $request)
    {
        $this->id = $id;
        $this->request = $request;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getRequest(): ImportRequest
    {
        return $this->request;
    }
}

class ImportJobRepository
{
    private $connection;

    public function create(ImportRequest $request): ImportJob
    {
        $id = $this->connection->table('import_jobs')->insertGetId([
            'format' => $request->getFormat(),
            'type' => $request->getType(),
            'options' => json_encode($request->getOptions()),
            'status' => 'pending',
            'created_at' => now()
        ]);

        return new ImportJob($id, $request);
    }

    public function markAsCompleted(ImportJob $job, ProcessingResult $result): void
    {
        $this->connection->table('import_jobs')
            ->where('id', $job->getId())
            ->update([
                'status' => 'completed',
                'processed_count' => $result->getProcessedCount(),
                'failed_count' => $result->getFailedCount(),
                'errors' => json_encode($result->getErrors()),
                'completed_at' => now()
            ]);
    }

    public function markAsFailed(ImportJob $job, string $error): void
    {
        $this->connection->table('import_jobs')
            ->where('id', $job->getId())
            ->update([
                'status' => 'failed',
                'error' => $error,
                'failed_at' => now()
            ]);
    }
}
