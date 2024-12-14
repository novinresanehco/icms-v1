// File: app/Core/Performance/Monitoring/PerformanceMonitor.php
<?php

namespace App\Core\Performance\Monitoring;

class PerformanceMonitor
{
    protected MetricsCollector $metrics;
    protected AlertManager $alerts;
    protected ThresholdManager $thresholds;
    protected ReportGenerator $reports;

    public function monitor(): void
    {
        // Collect current metrics
        $currentMetrics = $this->metrics->collect();
        
        // Check thresholds
        $violations = $this->checkThresholds($currentMetrics);
        
        // Handle violations
        if (!empty($violations)) {
            $this->handleViolations($violations);
        }
        
        // Generate reports
        $this->generateReports($currentMetrics);
    }

    protected function checkThresholds(array $metrics): array
    {
        return $this->thresholds->check($metrics);
    }

    protected function handleViolations(array $violations): void
    {
        foreach ($violations as $violation) {
            $this->alerts->notify($violation);
        }
    }
}

// File: app/Core/Performance/Metrics/MetricsCollector.php
<?php

namespace App\Core\Performance\Metrics;

class MetricsCollector
{
    protected MetricsStorage $storage;
    protected MetricsFormatter $formatter;
    protected array $collectors = [];

    public function collect(): array
    {
        $metrics = [];
        
        foreach ($this->collectors as $collector) {
            $metrics = array_merge($metrics, $collector->collect());
        }
        
        $formattedMetrics = $this->formatter->format($metrics);
        $this->storage->store($formattedMetrics);
        
        return $formattedMetrics;
    }

    public function registerCollector(MetricCollector $collector): void
    {
        $this->collectors[] = $collector;
    }

    public function getHistoricalMetrics(string $metric, DateRange $range): array
    {
        return $this->storage->query($metric, $range);
    }
}
