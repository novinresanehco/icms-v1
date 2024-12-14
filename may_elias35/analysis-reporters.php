<?php

namespace App\Core\Audit\Reporters;

class MetricsReporter
{
    private MetricsCollector $metrics;
    private array $formatters;
    private array $thresholds;

    public function __construct(
        MetricsCollector $metrics,
        array $formatters = [],
        array $thresholds = []
    ) {
        $this->metrics = $metrics;
        $this->formatters = $formatters;
        $this->thresholds = $thresholds;
    }

    public function generateReport(array $options = []): Report
    {
        $metrics = $this->metrics->collect();
        
        $data = [];
        foreach ($metrics as $metric) {
            if ($this->shouldInclude($metric, $options)) {
                $data[] = $this->formatMetric($metric);
            }
        }

        return new Report(
            $data,
            $this->analyzeThresholds($metrics),
            $this->generateSummary($metrics)
        );
    }

    private function shouldInclude(Metric $metric, array $options): bool
    {
        if (!empty($options['names']) && !in_array($metric->getName(), $options['names'])) {
            return false;
        }

        if (!empty($options['tags'])) {
            foreach ($options['tags'] as $key => $value) {
                if (!isset($metric->getTags()[$key]) || $metric->getTags()[$key] !== $value) {
                    return false;
                }
            }
        }

        return true;
    }

    private function formatMetric(Metric $metric): array
    {
        $formatter = $this->getFormatter($metric);
        return $formatter ? $formatter->format($metric) : $metric->toArray();
    }

    private function getFormatter(Metric $metric): ?MetricFormatter
    {
        foreach ($this->formatters as $formatter) {
            if ($formatter->supports($metric)) {
                return $formatter;
            }
        }
        return null;
    }

    private function analyzeThresholds(array $metrics): array
    {
        $violations = [];
        foreach ($metrics as $metric) {
            $threshold = $this->thresholds[$metric->getName()] ?? null;
            if ($threshold && !$threshold->check($metric)) {
                $violations[] = [
                    'metric' => $metric->getName(),
                    'value' => $metric->getValue(),
                    'threshold' => $threshold->getValue(),
                    'tags' => $metric->getTags()
                ];
            }
        }
        return $violations;
    }

    private function generateSummary(array $metrics): array
    {
        $summary = [];
        foreach ($metrics as $metric) {
            $name = $metric->getName();
            if (!isset($summary[$name])) {
                $summary[$name] = [
                    'count' => 0,
                    'min' => PHP_FLOAT_MAX,
                    'max' => PHP_FLOAT_MIN,
                    'sum' => 0
                ];
            }
            $summary[$name]['count']++;
            $summary[$name]['min'] = min($summary[$name]['min'], $metric->getValue());
            $summary[$name]['max'] = max($summary[$name]['max'], $metric->getValue());
            $summary[$name]['sum'] += $metric->getValue();
        }

        foreach ($summary as &$stats) {
            $stats['avg'] = $stats['sum'] / $stats['count'];
        }

        return $summary;
    }
}

class Report
{
    private array $data;
    private array $violations;
    private array $summary;
    private \DateTime $generatedAt;

    public function __construct(array $data, array $violations, array $summary)
    {
        $this->data = $data;
        $this->violations = $violations;
        $this->summary = $summary;
        $this->generatedAt = new \DateTime();
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getViolations(): array
    {
        return $this->violations;
    }

    public function getSummary(): array
    {
        return $this->summary;
    }

    public function getGeneratedAt(): \DateTime
    {
        return $this->generatedAt;
    }

    public function hasViolations(): bool
    {
        return !empty($this->violations);
    }

    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'violations' => $this->violations,
            'summary' => $this->summary,
            'generated_at' => $this->generatedAt->format(\DateTime::ISO8601)
        ];
    }
}
