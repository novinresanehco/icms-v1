<?php

namespace App\Core\Audit\Aggregates;

class ResultAggregate
{
    private array $results = [];
    private array $metrics = [];
    private array $metadata = [];

    public function addResult(AnalysisResult $result): void
    {
        $this->results[] = $result;
        $this->aggregateMetrics($result->getMetrics());
        $this->updateMetadata($result->getMetadata());
    }

    public function getResults(): array
    {
        return $this->results;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    private function aggregateMetrics(array $metrics): void
    {
        foreach ($metrics as $key => $value) {
            if (!isset($this->metrics[$key])) {
                $this->metrics[$key] = [];
            }
            $this->metrics[$key][] = $value;
        }
    }

    private function updateMetadata(array $metadata): void
    {
        $this->metadata = array_merge_recursive($this->metadata, $metadata);
    }
}

class MetricAggregate
{
    private array $values = [];
    private array $summary = [];
    private StatisticsCalculator $calculator;

    public function __construct(StatisticsCalculator $calculator)
    {
        $this->calculator = $calculator;
    }

    public function addValue(string $metric, $value): void
    {
        if (!isset($this->values[$metric])) {
            $this->values[$metric] = [];
        }
        $this->values[$metric][] = $value;
        $this->updateSummary($metric);
    }

    public function getSummary(string $metric): array
    {
        return $this->summary[$metric] ?? [];
    }

    private function updateSummary(string $metric): void
    {
        $values = $this->values[$metric];
        
        $this->summary[$metric] = [
            'count' => count($values),
            'mean' => $this->calculator->calculateMean($values),
            'median' => $this->calculator->calculateMedian($values),
            'std_dev' => $this->calculator->calculateStandardDeviation($values),
            'min' => min($values),
            'max' => max($values)
        ];
    }
}

class TimeSeriesAggregate
{
    private array $series = [];
    private int $interval;
    private string $aggregationType;

    public function __construct(int $interval, string $aggregationType = 'avg')
    {
        $this->interval = $interval;
        $this->aggregationType = $aggregationType;
    }

    public function addPoint(string $series, float $value, int $timestamp): void
    {
        $bucket = $this->getBucket($timestamp);
        
        if (!isset($this->series[$series][$bucket])) {
            $this->series[$series][$bucket] = [];
        }
        
        $this->series[$series][$bucket][] = $value;
    }

    public function getAggregatedSeries(string $series): array
    {
        if (!isset($this->series[$series])) {
            return [];
        }

        $result = [];
        foreach ($this->series[$series] as $bucket => $values) {
            $result[$bucket] = $this->aggregate($values);
        }
        
        return $result;
    }

    private function getBucket(int $timestamp): int
    {
        return floor($timestamp / $this->interval) * $this->interval;
    }

    private function aggregate(array $values): float
    {
        return match($this->aggregationType) {
            'sum' => array_sum($values),
            'avg' => array_sum($values) / count($values),
            'min' => min($values),
            'max' => max($values),
            default => array_sum($values) / count($values)
        };
    }
}
