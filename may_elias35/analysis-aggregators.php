<?php

namespace App\Core\Audit\Aggregators;

class MetricsAggregator
{
    private array $metrics = [];
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function addMetric(string $name, $value, array $tags = []): void
    {
        $key = $this->generateKey($name, $tags);
        if (!isset($this->metrics[$key])) {
            $this->metrics[$key] = [];
        }
        $this->metrics[$key][] = $value;
    }

    public function getAggregates(): array
    {
        $results = [];
        foreach ($this->metrics as $key => $values) {
            $results[$key] = [
                'count' => count($values),
                'sum' => array_sum($values),
                'avg' => array_sum($values) / count($values),
                'min' => min($values),
                'max' => max($values)
            ];
        }
        return $results;
    }

    private function generateKey(string $name, array $tags): string
    {
        ksort($tags);
        return $name . ':' . http_build_query($tags);
    }
}

class TimeSeriesAggregator
{
    private array $series = [];
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function addPoint(string $series, float $value, \DateTime $timestamp): void
    {
        $bucket = $this->getBucket($timestamp);
        
        if (!isset($this->series[$series][$bucket])) {
            $this->series[$series][$bucket] = [];
        }
        
        $this->series[$series][$bucket][] = $value;
    }

    public function aggregate(): array
    {
        $results = [];
        foreach ($this->series as $series => $buckets) {
            $results[$series] = [];
            foreach ($buckets as $bucket => $values) {
                $results[$series][$bucket] = [
                    'value' => $this->calculateValue($values),
                    'count' => count($values)
                ];
            }
        }
        return $results;
    }

    private function getBucket(\DateTime $timestamp): string
    {
        $interval = $this->config['bucket_interval'] ?? '1 hour';
        return $timestamp->format('Y-m-d H:00:00');
    }

    private function calculateValue(array $values): float
    {
        $method = $this->config['aggregation_method'] ?? 'avg';
        
        return match($method) {
            'sum' => array_sum($values),
            'avg' => array_sum($values) / count($values),
            'min' => min($values),
            'max' => max($values),
            default => array_sum($values) / count($values)
        };
    }
}

class ResultAggregator
{
    private array $results = [];
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    public function addResult(AnalysisResult $result): void
    {
        $key = $this->generateKey($result);
        if (!isset($this->results[$key])) {
            $this->results[$key] = [];
        }
        $this->results[$key][] = $result;
    }

    public function aggregate(): array
    {
        $aggregates = [];
        foreach ($this->results as $key => $results) {
            $aggregates[$key] = [
                'count' => count($results),
                'success_rate' => $this->calculateSuccessRate($results),
                'average_duration' => $this->calculateAverageDuration($results),
                'metrics' => $this->aggregateMetrics($results)
            ];
        }
        return $aggregates;
    }

    private function generateKey(AnalysisResult $result): string
    {
        $parts = array_map(
            fn($field) => $result->getData()[$field] ?? '',
            $this->config['group_by'] ?? []
        );
        return implode(':', $parts);
    }

    private function calculateSuccessRate(array $results): float
    {
        $successful = array_filter($results, fn($r) => $r->isSuccessful());
        return count($successful) / count($results) * 100;
    }

    private function calculateAverageDuration(array $results): float
    {
        $durations = array_map(fn($r) => $r->getDuration(), $results);
        return array_sum($durations) / count($durations);
    }

    private function aggregateMetrics(array $results): array
    {
        $metrics = [];
        foreach ($results as $result) {
            foreach ($result->getMetrics() as $key => $value) {
                if (!isset($metrics[$key])) {
                    $metrics[$key] = [];
                }
                $metrics[$key][] = $value;
            }
        }

        return array_map(function($values) {
            return [
                'avg' => array_sum($values) / count($values),
                'min' => min($values),
                'max' => max($values)
            ];
        }, $metrics);
    }
}
