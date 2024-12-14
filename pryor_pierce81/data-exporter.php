<?php

namespace App\Core\Export;

class DataExporter
{
    private array $formatters = [];
    private array $writers = [];
    private ExportJobRepository $repository;

    public function export(ExportRequest $request): ExportResult
    {
        $job = $this->repository->create($request);

        try {
            $data = $this->fetchData($request);
            $formatter = $this->getFormatter($request->getFormat());
            $writer = $this->getWriter($request->getFormat());

            $formattedData = $formatter->format($data, $request->getOptions());
            $result = $writer->write($formattedData, $job->getId());

            $this->repository->markAsCompleted($job, $result->getFilePath());
            return $result;

        } catch (\Exception $e) {
            $this->repository->markAsFailed($job, $e->getMessage());
            throw new ExportException("Export failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function registerFormatter(string $format, DataFormatter $formatter): void
    {
        $this->formatters[$format] = $formatter;
    }

    public function registerWriter(string $format, DataWriter $writer): void
    {
        $this->writers[$format] = $writer;
    }

    private function fetchData(ExportRequest $request): array
    {
        $query = $request->getQuery();
        return $query->get()->toArray();
    }

    private function getFormatter(string $format): DataFormatter
    {
        if (!isset($this->formatters[$format])) {
            throw new ExportException("Unsupported format: {$format}");
        }
        return $this->formatters[$format];
    }

    private function getWriter(string $format): DataWriter
    {
        if (!isset($this->writers[$format])) {
            throw new ExportException("Unsupported format: {$format}");
        }
        return $this->writers[$format];
    }
}

class CsvFormatter implements DataFormatter
{
    public function format(array $data, array $options = []): string
    {
        $output = fopen('php://temp', 'r+');
        $headers = $options['headers'] ?? array_keys(reset($data));

        fputcsv($output, $headers);

        foreach ($data as $row) {
            fputcsv($output, array_map(function ($field) {
                return is_array($field) ? json_encode($field) : $field;
            }, $row));
        }

        rewind($output);
        $content = stream_get_contents($output);
        fclose($output);

        return $content;
    }
}

class ExcelFormatter implements DataFormatter
{
    public function format(array $data, array $options = []): string
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $headers = $options['headers'] ?? array_keys(reset($data));
        $row = 1;

        foreach ($headers as $col => $header) {
            $sheet->setCellValueByColumnAndRow($col + 1, $row, $header);
        }

        foreach ($data as $rowData) {
            $row++;
            foreach ($rowData as $col => $value) {
                $sheet->setCellValueByColumnAndRow($col + 1, $row, $value);
            }
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'export');
        $writer->save($tempFile);

        return $tempFile;
    }
}

class JsonFormatter implements DataFormatter
{
    public function format(array $data, array $options = []): string
    {
        $flags = JSON_PRETTY_PRINT;
        if ($options['preserve_zero_fraction'] ?? false) {
            $flags |= JSON_PRESERVE_ZERO_FRACTION;
        }

        return json_encode($data, $flags);
    }
}

class FileWriter implements DataWriter
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
    }

    public function write($content, string $identifier): ExportResult
    {
        $path = $this->generatePath($identifier);
        file_put_contents($path, $content);

        return new ExportResult($path);
    }

    private function generatePath(string $identifier): string
    {
        return $this->basePath . '/' . $identifier . '_' . date('YmdHis');
    }
}

class S3Writer implements DataWriter
{
    private $s3Client;
    private string $bucket;

    public function write($content, string $identifier): ExportResult
    {
        $key = $this->generateKey($identifier);

        $this->s3Client->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Body' => $content
        ]);

        return new ExportResult($key);
    }

    private function generateKey(string $identifier): string
    {
        return 'exports/' . $identifier . '_' . date('YmdHis');
    }
}

class ExportRequest
{
    private $query;
    private string $format;
    private array $options;

    public function __construct($query, string $format, array $options = [])
    {
        $this->query = $query;
        $this->format = $format;
        $this->options = $options;
    }

    public function getQuery()
    {
        return $this->query;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function getOptions(): array
    {
        return $this->options;
    }
}

class ExportResult
{
    private string $filePath;

    public function __construct(string $filePath)
    {
        $this->filePath = $filePath;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }
}

class ExportJobRepository
{
    private $connection;

    public function create(ExportRequest $request): ExportJob
    {
        $id = $this->connection->table('export_jobs')->insertGetId([
            'format' => $request->getFormat(),
            'options' => json_encode($request->getOptions()),
            'status' => 'pending',
            'created_at' => now()
        ]);

        return new ExportJob($id, $request);
    }

    public function markAsCompleted(ExportJob $job, string $filePath): void
    {
        $this->connection->table('export_jobs')
            ->where('id', $job->getId())
            ->update([
                'status' => 'completed',
                'file_path' => $filePath,
                'completed_at' => now()
            ]);
    }

    public function markAsFailed(ExportJob $job, string $error): void
    {
        $this->connection->table('export_jobs')
            ->where('id', $job->getId())
            ->update([
                'status' => 'failed',
                'error' => $error,
                'failed_at' => now()
            ]);
    }
}

interface DataFormatter
{
    public function format(array $data, array $options = []): string;
}

interface DataWriter
{
    public function write($content, string $identifier): ExportResult;
}

class ExportJob
{
    private int $id;
    private ExportRequest $request;

    public function __construct(int $id, ExportRequest $request)
    {
        $this->id = $id;
        $this->request = $request;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getRequest(): ExportRequest
    {
        return $this->request;
    }
}
