<?php

namespace App\Core\Reports;

class ReportGenerator
{
    private array $processors = [];
    private array $formatters = [];
    private ReportCache $cache;
    private ReportRepository $repository;

    public function generate(ReportDefinition $definition): Report
    {
        $cacheKey = $this->getCacheKey($definition);
        
        if ($this->cache->has($cacheKey)) {
            return $this->cache->get($cacheKey);
        }

        $data = $this->processData($definition);
        $report = new Report($definition, $data);
        
        $this->repository->save($report);
        $this->cache->set($cacheKey, $report);
        
        return $report;
    }

    public function registerProcessor(string $type, DataProcessor $processor): void
    {
        $this->processors[$type] = $processor;
    }

    public function registerFormatter(string $format, ReportFormatter $formatter): void
    {
        $this->formatters[$format] = $formatter;
    }

    private function processData(ReportDefinition $definition): array
    {
        $processor = $this->processors[$definition->getType()]
            ?? throw new ReportException("Unknown report type: {$definition->getType()}");

        return $processor->process($definition);
    }

    private function getCacheKey(ReportDefinition $definition): string
    {
        return md5(serialize($definition));
    }
}

class Report
{
    private ReportDefinition $definition;
    private array $data;
    private \DateTime $generatedAt;

    public function __construct(ReportDefinition $definition, array $data)
    {
        $this->definition = $definition;
        $this->data = $data;
        $this->generatedAt = new \DateTime();
    }

    public function export(string $format): string
    {
        $formatter = $this->getFormatter($format);
        return $formatter->format($this->data);
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getDefinition(): ReportDefinition
    {
        return $this->definition;
    }

    public function getGeneratedAt(): \DateTime
    {
        return $this->generatedAt;
    }
}

class ReportDefinition
{
    private string $type;
    private array $parameters;
    private array $filters;
    private array $sorting;
    private array $options;

    public function __construct(
        string $type,
        array $parameters = [],
        array $filters = [],
        array $sorting = [],
        array $options = []
    ) {
        $this->type = $type;
        $this->parameters = $parameters;
        $this->filters = $filters;
        $this->sorting = $sorting;
        $this->options = $options;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getFilters(): array
    {
        return $this->filters;
    }

    public function getSorting(): array
    {
        return $this->sorting;
    }

    public function getOptions(): array
    {
        return $this->options;
    }
}

interface DataProcessor
{
    public function process(ReportDefinition $definition): array;
}

interface ReportFormatter
{
    public function format(array $data): string;
}

class CsvFormatter implements ReportFormatter
{
    public function format(array $data): string
    {
        $output = fopen('php://temp', 'r+');
        
        // Write headers
        if (!empty($data)) {
            fputcsv($output, array_keys(reset($data)));
        }

        // Write data
        foreach ($data as $row) {
            fputcsv($output, $row);
        }

        rewind($output);
        $content = stream_get_contents($output);
        fclose($output);

        return $content;
    }
}

class JsonFormatter implements ReportFormatter
{
    public function format(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT);
    }
}

class PdfFormatter implements ReportFormatter
{
    private string $template;

    public function format(array $data): string
    {
        $html = $this->renderTemplate($this->template, $data);
        return $this->convertToPdf($html);
    }

    private function renderTemplate(string $template, array $data): string
    {
        // Template rendering logic
        return '';
    }

    private function convertToPdf(string $html): string
    {
        // PDF conversion logic
        return '';
    }
}

class ReportCache
{
    private $connection;
    private int $ttl;

    public function has(string $key): bool
    {
        return $this->connection->exists($key);
    }

    public function get(string $key): ?Report
    {
        $data = $this->connection->get($key);
        return $data ? unserialize($data) : null;
    }

    public function set(string $key, Report $report): void
    {
        $this->connection->setex(
            $key,
            $this->ttl,
            serialize($report)
        );
    }
}

class ReportRepository
{
    private $connection;

    public function save(Report $report): void
    {
        $this->connection->table('reports')->insert([
            'type' => $report->getDefinition()->getType(),
            'parameters' => json_encode($report->getDefinition()->getParameters()),
            'data' => json_encode($report->getData()),
            'generated_at' => $report->getGeneratedAt()
        ]);
    }

    public function findByType(string $type): array
    {
        return $this->connection->table('reports')
            ->where('type', $type)
            ->orderBy('generated_at', 'desc')
            ->get()
            ->map(fn($row) => $this->hydrate($row))
            ->toArray();
    }

    private function hydrate($row): Report
    {
        return new Report(
            new ReportDefinition(
                $row->type,
                json_decode($row->parameters, true)
            ),
            json_decode($row->data, true)
        );
    }
}

class ReportException extends \Exception {}
