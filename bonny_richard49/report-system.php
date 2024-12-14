<?php

namespace App\Core\Reports\Contracts;

interface ReportGeneratorInterface
{
    public function generateReport(string $type, array $parameters = []): Report;
    public function exportReport(Report $report, string $format): string;
    public function scheduleReport(string $type, array $parameters, Schedule $schedule): string;
    public function getReport(string $reportId): ?Report;
}

interface ReportFormatterInterface
{
    public function format(array $data, array $options = []): string;
    public function supports(string $format): bool;
}

namespace App\Core\Reports\Services;

class ReportService implements ReportGeneratorInterface
{
    protected ReportManager $manager;
    protected DataCollector $collector;
    protected FormatterManager $formatter;
    protected ScheduleManager $scheduler;
    protected StorageManager $storage;

    public function __construct(
        ReportManager $manager,
        DataCollector $collector,
        FormatterManager $formatter,
        ScheduleManager $scheduler,
        StorageManager $storage
    ) {
        $this->manager = $manager;
        $this->collector = $collector;
        $this->formatter = $formatter;
        $this->scheduler = $scheduler;
        $this->storage = $storage;
    }

    public function generateReport(string $type, array $parameters = []): Report
    {
        try {
            // Get report definition
            $definition = $this->manager->getDefinition($type);

            // Collect data
            $data = $this->collector->collect($definition, $parameters);

            // Process data
            $processedData = $this->processData($data, $definition->getProcessors());

            // Create report
            $report = new Report([
                'id' => Str::uuid(),
                'type' => $type,
                'parameters' => $parameters,
                'data' => $processedData,
                'generated_at' => now()
            ]);

            // Store report
            $this->storage->store($report);

            return $report;
        } catch (\Exception $e) {
            throw new ReportGenerationException(
                "Failed to generate report: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    public function exportReport(Report $report, string $format): string
    {
        try {
            // Get formatter
            $formatter = $this->formatter->getFormatter($format);

            // Format report
            $formatted = $formatter->format($report->getData(), $report->getParameters());

            // Store formatted report
            return $this->storage->storeFormatted($report->getId(), $format, $formatted);
        } catch (\Exception $e) {
            throw new ReportExportException(
                "Failed to export report: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    public function scheduleReport(string $type, array $parameters, Schedule $schedule): string
    {
        return $this->scheduler->schedule($type, $parameters, $schedule);
    }

    public function getReport(string $reportId): ?Report
    {
        return $this->storage->find($reportId);
    }

    protected function processData(array $data, array $processors): array
    {
        foreach ($processors as $processor) {
            $data = $processor->process($data);
        }

        return $data;
    }
}

namespace App\Core\Reports\Services;

class ReportManager
{
    protected array $definitions = [];
    protected array $processors = [];

    public function registerDefinition(ReportDefinition $definition): void
    {
        $this->definitions[$definition->getType()] = $definition;
    }

    public function registerProcessor(DataProcessor $processor): void
    {
        $this->processors[] = $processor;
    }

    public function getDefinition(string $type): ReportDefinition
    {
        if (!isset($this->definitions[$type])) {
            throw new ReportDefinitionNotFoundException("Report type not found: {$type}");
        }

        return $this->definitions[$type];
    }

    public function getProcessors(): array
    {
        return $this->processors;
    }
}

namespace App\Core\Reports\Services;

class DataCollector
{
    protected array $collectors = [];
    protected QueryBuilder $queryBuilder;
    protected Cache $cache;

    public function collect(ReportDefinition $definition, array $parameters): array
    {
        $data = [];

        foreach ($definition->getDataSources() as $source) {
            if (isset($this->collectors[$source->getType()])) {
                $collector = $this->collectors[$source->getType()];
                $sourceData = $this->collectFromSource($collector, $source, $parameters);
                $data = array_merge($data, $sourceData);
            }
        }

        return $data;
    }

    protected function collectFromSource(
        DataCollectorInterface $collector,
        DataSource $source,
        array $parameters
    ): array {
        $cacheKey = $this->generateCacheKey($source, $parameters);

        return $this->cache->remember($cacheKey, $source->getCacheDuration(), function () use ($collector, $source, $parameters) {
            return $collector->collect($source, $parameters);
        });
    }

    protected function generateCacheKey(DataSource $source, array $parameters): string
    {
        return sprintf(
            'report_data:%s:%s',
            $source->getName(),
            md5(serialize($parameters))
        );
    }
}

namespace App\Core\Reports\Services;

class FormatterManager
{
    protected array $formatters = [];

    public function registerFormatter(string $format, ReportFormatterInterface $formatter): void
    {
        $this->formatters[$format] = $formatter;
    }

    public function getFormatter(string $format): ReportFormatterInterface
    {
        if (!isset($this->formatters[$format])) {
            throw new UnsupportedFormatException("Format not supported: {$format}");
        }

        return $this->formatters[$format];
    }
}

namespace App\Core\Reports\Formatters;

class PDFFormatter implements ReportFormatterInterface
{
    protected PDFGenerator $generator;
    protected TemplateEngine $templateEngine;

    public function format(array $data, array $options = []): string
    {
        // Prepare template
        $template = $this->templateEngine->render(
            $options['template'] ?? 'default',
            $data
        );

        // Generate PDF
        return $this->generator->generate($template, [
            'orientation' => $options['orientation'] ?? 'portrait',
            'size' => $options['size'] ?? 'A4',
            'margin' => $options['margin'] ?? ['top' => 10, 'right' => 10, 'bottom' => 10, 'left' => 10]
        ]);
    }

    public function supports(string $format): bool
    {
        return $format === 'pdf';
    }
}

class ExcelFormatter implements ReportFormatterInterface
{
    protected SpreadsheetGenerator $generator;

    public function format(array $data, array $options = []): string
    {
        // Create spreadsheet
        $spreadsheet = $this->generator->create();

        // Add data
        $this->addData($spreadsheet, $data, $options);

        // Style spreadsheet
        $this->applyStyles($spreadsheet, $options);

        // Generate file
        return $this->generator->generate($spreadsheet, $options['format'] ?? 'xlsx');
    }

    public function supports(string $format): bool
    {
        return in_array($format, ['xlsx', 'xls', 'csv']);
    }

    protected function addData(Spreadsheet $spreadsheet, array $data, array $options): void
    {
        $sheet = $spreadsheet->getActiveSheet();

        // Add headers
        if (!empty($options['headers'])) {
            $sheet->fromArray($options['headers'], null, 'A1');
        }

        // Add data
        $sheet->fromArray($data, null, 'A2');
    }

    protected function applyStyles(Spreadsheet $spreadsheet, array $options): void
    {
        $sheet = $spreadsheet->getActiveSheet();

        // Style headers
        if (!empty($options['headers'])) {
            $headerStyle = $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1');
            $headerStyle->getFont()->setBold(true);
        }

        // Auto size columns
        foreach (range('A', $sheet->getHighestColumn()) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }
}

namespace App\Core\Reports\Models;

class Report
{
    protected string $id;
    protected string $type;
    protected array $parameters;
    protected array $data;
    protected Carbon $generatedAt;

    public function __construct(array $attributes)
    {
        $this->id = $attributes['id'];
        $this->type = $attributes['type'];
        $this->parameters = $attributes['parameters'];
        $this->data = $attributes['data'];
        $this->generatedAt = $attributes['generated_at'];
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getParameters(): array
    {
        return $this->parameters;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getGeneratedAt(): Carbon
    {
        return $this->generatedAt;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'parameters' => $this->parameters,
            'data' => $this->data,
            'generated_at' => $this->generatedAt->toIso8601String()
        ];
    }
}
