<?php

namespace App\Core\Monitoring\Metrics;

class MetricsProcessor
{
    private MetricsCollector $collector;
    private MetricsAggregator $aggregator;
    private MetricsAnalyzer $analyzer;
    private MetricsStorage $storage;
    private AlertManager $alertManager;

    public function process(): ProcessingResult
    {
        $metrics = $this->collector->collect();
        $aggregated = $this->aggregator->aggregate($metrics);
        $analysis = $this->analyzer->analyze($aggregated);
        
        $this->storage->store($metrics, $aggregated, $analysis);

        if ($analysis->hasIssues()) {
            $this->alertManager->notify(new MetricsAlert($analysis));
        }

        return new ProcessingResult($metrics, $aggregated, $analysis);
    }
}

class MetricsCollector
{
    private array $collectors;
    private CollectionFilter $filter;
    private DataFormatter $formatter;

    public function collect(): MetricsCollection
    {
        $metrics = [];
        
        foreach ($this->collectors as $collector) {
            try {
                $data = $collector->collect();
                $filtered = $this->filter->filter($data);
                $formatted = $this->formatter->format($filtered);
                $metrics[$collector->getName()] = $formatted;
            } catch (\Exception $e) {
                $metrics[$collector->getName()] = new CollectionError($e);
            }
        }

        return new MetricsCollection($metrics);
    }
}

class MetricsAggregator
{
    private array $aggregators;
    private TimeWindow $window;
    private GroupingStrategy $grouping;

    public function aggregate(MetricsCollection $metrics): AggregatedMetrics
    {
        $aggregated = [];
        
        foreach ($this->aggregators as $aggregator) {
            $result = $aggregator->aggregate($metrics, $this->window, $this->grouping);
            $aggregated[$aggregator->getType()] = $result;
        }

        return new AggregatedMetrics($aggregated);
    }
}

class MetricsAnalyzer
{
    private ThresholdAnalyzer $thresholds;
    private TrendAnalyzer $trends;
    private PatternDetector $patterns;
    private AnomalyDetector $anomalies;

    public function analyze(AggregatedMetrics $metrics): MetricsAnalysis
    {
        return new MetricsAnalysis(
            $this->thresholds->analyze($metrics),
            $this->trends->analyze($metrics),
            $this->patterns->detect($metrics),
            $this->anomalies->detect($metrics)
        );
    }
}

class MetricsStorage
{
    private StorageAdapter $adapter;
    private RetentionPolicy $retention;
    private CompressionStrategy $compression;

    public function store(
        MetricsCollection $metrics,
        AggregatedMetrics $aggregated,
        MetricsAnalysis $analysis
    ): void {
        $data = [
            'raw' => $metrics->toArray(),
            'aggregated' => $aggregated->toArray(),
            'analysis' => $analysis->toArray(),
            'timestamp' => microtime(true)
        ];

        $compressed = $this->compression->compress($data);
        $this->adapter->store($compressed);
        $this->retention->apply($this->adapter);
    }
}

class ProcessingResult
{
    private MetricsCollection $metrics;
    private AggregatedMetrics $aggregated;
    private MetricsAnalysis $analysis;
    private float $timestamp;

    public function __construct(
        MetricsCollection $metrics,
        AggregatedMetrics $aggregated,
        MetricsAnalysis $analysis
    ) {
        $this->metrics = $metrics;
        $this->aggregated = $aggregated;
        $this->analysis = $analysis;
        $this->timestamp = microtime(true);
    }

    public function getMetrics(): MetricsCollection
    {
        return $this->metrics;
    }

    public function getAggregated(): AggregatedMetrics
    {
        return $this->aggregated;
    }

    public function getAnalysis(): MetricsAnalysis
    {
        return $this->analysis;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }
}

class MetricsCollection
{
    private array $metrics;
    private array $errors;
    private int $count;

    public function __construct(array $metrics)
    {
        $this->metrics = array_filter($metrics, fn($m) => !($m instanceof CollectionError));
        $this->errors = array_filter($metrics, fn($m) => $m instanceof CollectionError);
        $this->count = count($this->metrics);
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function toArray(): array
    {
        return [
            'metrics' => $this->metrics,
            'errors' => $this->errors,
            'count' => $this->count
        ];
    }
}

class AggregatedMetrics
{
    private array $data;
    private array $summary;
    private float $timestamp;

    public function __construct(array $data)
    {
        $this->data = $data;
        $this->summary = $this->generateSummary();
        $this->timestamp = microtime(true);
    }

    private function generateSummary(): array
    {
        $summary = [];
        foreach ($this->data as $type => $metrics) {
            $summary[$type] = [
                'count' => count($metrics),
                'min' => min($metrics),
                'max' => max($metrics),
                'avg' => array_sum($metrics) / count($metrics)
            ];
        }
        return $summary;
    }

    public function toArray(): array
    {
        return [
            'data' => $this->data,
            'summary' => $this->summary,
            'timestamp' => $this->timestamp
        ];
    }
}

class MetricsAnalysis
{
    private array $thresholds;
    private array $trends;
    private array $patterns;
    private array $anomalies;

    public function __construct(array $thresholds, array $trends, array $patterns, array $anomalies)
    {
        $this->thresholds = $thresholds;
        $this->trends = $trends;
        $this->patterns = $patterns;
        $this->anomalies = $anomalies;
    }

    public function hasIssues(): bool
    {
        return !empty($this->thresholds) || !empty($this->anomalies);
    }

    public function toArray(): array
    {
        return [
            'thresholds' => $this->thresholds,
            'trends' => $this->trends,
            'patterns' => $this->patterns,
            'anomalies' => $this->anomalies
        ];
    }
}
