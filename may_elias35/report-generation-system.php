// File: app/Core/Report/Manager/ReportManager.php
<?php

namespace App\Core\Report\Manager;

class ReportManager
{
    protected ReportGenerator $generator;
    protected DataCollector $collector;
    protected ReportCache $cache;
    protected ExportManager $exportManager;

    public function generate(ReportRequest $request): Report
    {
        if ($cached = $this->cache->get($request)) {
            return $cached;
        }

        $data = $this->collector->collect($request);
        $report = $this->generator->generate($data, $request->getType());
        
        $this->cache->put($request, $report);
        return $report;
    }

    public function export(Report $report, string $format): string
    {
        return $this->exportManager->export($report, $format);
    }

    public function schedule(ReportSchedule $schedule): void
    {
        $this->scheduler->schedule($schedule, function() use ($schedule) {
            $report = $this->generate($schedule->getRequest());
            $this->distributeReport($report, $schedule->getRecipients());
        });
    }
}

// File: app/Core/Report/Generator/ReportGenerator.php
<?php

namespace App\Core\Report\Generator;

class ReportGenerator
{
    protected TemplateManager $templateManager;
    protected DataProcessor $processor;
    protected ChartGenerator $chartGenerator;
    protected MetricsCalculator $calculator;

    public function generate(array $data, string $type): Report
    {
        // Process data
        $processedData = $this->processor->process($data);
        
        // Calculate metrics
        $metrics = $this->calculator->calculate($processedData);
        
        // Generate charts
        $charts = $this->generateCharts($processedData);
        
        // Generate report content
        $content = $this->generateContent($processedData, $metrics, $charts);
        
        return new Report([
            'type' => $type,
            'data' => $processedData,
            'metrics' => $metrics,
            'charts' => $charts,
            'content' => $content
        ]);
    }

    protected function generateCharts(array $data): array
    {
        $charts = [];
        foreach ($this->getChartTypes() as $type => $config) {
            $charts[$type] = $this->chartGenerator->generate($data, $config);
        }
        return $charts;
    }
}

// File: app/Core/Report/Export/ExportManager.php
<?php

namespace App\Core\Report\Export;

class ExportManager
{
    protected array $exporters = [];
    protected StyleManager $styleManager;
    protected FormatConfig $config;

    public function export(Report $report, string $format): string
    {
        $exporter = $this->getExporter($format);
        
        if (!$exporter) {
            throw new UnsupportedFormatException("Format not supported: {$format}");
        }

        return $exporter->export($report);
    }

    public function addExporter(string $format, Exporter $exporter): void
    {
        $this->exporters[$format] = $exporter;
    }

    protected function getExporter(string $format): ?Exporter
    {
        return $this->exporters[$format] ?? null;
    }
}

// File: app/Core/Report/Template/TemplateManager.php
<?php

namespace App\Core\Report\Template;

class TemplateManager
{
    protected TemplateRepository $repository;
    protected TemplateCompiler $compiler;
    protected VariableProcessor $variables;

    public function render(Template $template, array $data): string
    {
        $compiled = $this->compiler->compile($template);
        $processed = $this->variables->process($data);
        
        return $compiled->render($processed);
    }

    public function createTemplate(array $data): Template
    {
        return $this->repository->create([
            'name' => $data['name'],
            'content' => $data['content'],
            'variables' => $data['variables'] ?? [],
            'type' => $data['type']
        ]);
    }
}
