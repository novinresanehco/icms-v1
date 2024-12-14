<?php

namespace App\Core\Notification\Analytics\Aggregator;

class DataAggregator
{
    private array $aggregators = [];
    private array $pipeline = [];
    private array $metrics = [];

    public function registerAggregator(string $name, callable $aggregator): void
    {
        $this->aggregators[$name] = $aggregator;
    }

    public function addToPipeline(string $aggregator, array $config = []): void
    {
        if (!isset($this->aggregators[$aggregator])) {
            throw new \InvalidArgumentException("Aggregator not found: {$aggregator}");
        }

        $this->pipeline[] = [
            'aggregator' => $aggregator,
            'config' => $config
        ];
    }

    public function aggregate(array $data): array
    {
        $result = $data;
        $startTime = microtime(true);

        foreach ($this->pipeline as $step) {
            $stepStart = microtime(true);
            
            try {
                $result = ($this->aggregators[$step['aggregator']])($result, $step['config']);
                $this->recordMetrics($step['aggregator'], microtime(true) - $stepStart, true);
            } catch (\Exception $e) {
                $this->recordMetrics($step['aggregator'], microtime(true) - $stepStart, false);
                throw $e;
            }
        }

        $this->metrics['total_time'] = microtime(true) - $startTime;
        return $result;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    private function recordMetrics(string $aggregator, float $duration, bool $success): void
    {
        if (!isset($this->metrics[$aggregator])) {
            $this->metrics[$aggregator] = [
                'executions' => 0,
                'failures' => 0,
                'total_time' => 0,
                'avg_time' => 0
            ];
        }

        $this->metrics[$aggregator]['executions']++;
        if (!$success) {
            $this->metrics[$aggregator]['failures']++;
        }
        $this->metrics[$aggregator]['total_time'] += $duration;
        $this->metrics[$aggregator]['avg_time'] = 
            $this->metrics[$aggregator]['total_time'] / $this->metrics[$aggregator]['executions'];
    }
}

class TimeSeriesAggregator
{
    public static function aggregate(array $data, array $config = []): array
    {
        $interval = $config['interval'] ?? 3600;
        $aggregated = [];

        foreach ($data as $item) {
            $timestamp = self::normalizeTimestamp($item['timestamp'], $interval);
            
            if (!isset($aggregated[$timestamp])) {
                $aggregated[$timestamp] = [
                    'count' => 0,
                    'sum' => 0,
                    'min' => PHP_FLOAT_MAX,
                    'max' => PHP_FLOAT_MIN
                ];
            }

            $value = $item['value'];
            $aggregated[$timestamp]['count']++;
            $aggregated[$timestamp]['sum'] += $value;
            $aggregated[$timestamp]['min'] = min($aggregated[$timestamp]['min'], $value);
            $aggregated[$timestamp]['max'] = max($aggregated[$timestamp]['max'], $value);
            $aggregated[$timestamp]['avg'] = $aggregated[$timestamp]['sum'] / $aggregated[$timestamp]['count'];
        }

        return $aggregated;
    }

    private static function normalizeTimestamp(int $timestamp, int $interval): int
    {
        return floor($timestamp / $interval) * $interval;
    }
}

class DimensionalAggregator
{
    public static function aggregate(array $data, array $config = []): array
    {
        $dimensions = $config['dimensions'] ?? [];
        $metrics = $config['metrics'] ?? [];
        $aggregated = [];

        foreach ($data as $item) {
            $key = self::buildKey($item, $dimensions);
            
            if (!isset($aggregated[$key])) {
                $aggregated[$key] = self::initializeMetrics($metrics);
            }

            self::updateMetrics($aggregated[$key], $item, $metrics);
        }

        return $aggregated;
    }

    private static function buildKey(array $item, array $dimensions): string
    {
        $keyParts = [];
        foreach ($dimensions as $dimension) {
            $keyParts[] = $item[$dimension] ?? 'unknown';
        }
        return implode(':', $keyParts);
    }

    private static function initializeMetrics(array $metrics): array
    {
        $initialized = [];
        foreach ($metrics as $metric) {
            $initialized[$metric] = [
                'count' => 0,
                'sum' => 0,
                'min' => PHP_FLOAT_MAX,
                'max' => PHP_FLOAT_MIN
            ];
        }
        return $initialized;
    }

    private static function updateMetrics(array &$aggregated, array $item, array $metrics): void
    {
        foreach ($metrics as $metric) {
            if (isset($item[$metric])) {
                $value = $item[$metric];
                $aggregated[$metric]['count']++;
                $aggregated[$metric]['sum'] += $value;
                $aggregated[$metric]['min'] = min($aggregated[$metric]['min'], $value);
                $aggregated[$metric]['max'] = max($aggregated[$metric]['max'], $value);
                $aggregated[$metric]['avg'] = $aggregated[$metric]['sum'] / $aggregated[$metric]['count'];
            }
        }
    }
}
