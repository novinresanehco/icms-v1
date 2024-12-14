<?php

namespace App\Core\Notification\Analytics\Metrics;

class MetricsCalculator
{
    private array $metrics = [];
    private array $thresholds;
    private array $aggregators;

    public function __construct(array $thresholds = [])
    {
        $this->thresholds = $thresholds;
        $this->initializeAggregators();
    }

    public function addMetric(string $name, $value, array $tags = []): void
    {
        $this->metrics[] = [
            'name' => $name,
            'value' => $value,
            'tags' => $tags,
            'timestamp' => time()
        ];
    }

    public function calculateStatistics(array $options = []): array
    {
        $stats = [];
        foreach ($this->aggregators as $name => $aggregator) {
            $stats[$name] = $aggregator($this->metrics, $options);
        }
        return $stats;
    }

    public function detectAnomalies(string $metricName): array
    {
        $values = $this->getMetricValues($metricName);
        if (empty($values)) {
            return [];
        }

        $mean = array_sum($values) / count($values);
        $stdDev = $this->calculateStdDev($values, $mean);
        $threshold = $stdDev * 2;

        $anomalies = [];
        foreach ($values as $timestamp => $value) {
            if (abs($value - $mean) > $threshold) {
                $anomalies[] = [
                    'timestamp' => $timestamp,
                    'value' => $value,
                    'deviation' => abs($value - $mean) / $stdDev
                ];
            }
        }

        return $anomalies;
    }

    public function calculatePercentiles(string $metricName, array $percentiles): array
    {
        $values = $this->getMetricValues($metricName);
        if (empty($values)) {
            return [];
        }

        sort($values);
        $results = [];
        foreach ($percentiles as $p) {
            $results[$p] = $this->calculatePercentile($values, $p);
        }
        return $results;
    }

    public function calculateMovingAverage(string $metricName, int $window): array
    {
        $values = $this->getMetricValues($metricName);
        if (empty($values)) {
            return [];
        }

        $result = [];
        $count = count($values);
        for ($i = $window - 1; $i < $count; $i++) {
            $sum = 0;
            for ($j = 0; $j < $window; $j++) {
                $sum += $values[$i - $j];
            }
            $result[array_keys($values)[$i]] = $sum / $window;
        }
        return $result;
    }

    private function getMetricValues(string $metricName): array
    {
        $values = [];
        foreach ($this->metrics as $metric) {
            if ($metric['name'] === $metricName) {
                $values[$metric['timestamp']] = $metric['value'];
            }
        }
        return $values;
    }

    private function calculateStdDev(array $values, float $mean): float
    {
        $variance = 0;
        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }
        return sqrt($variance / count($values));
    }

    private function calculatePercentile(array $values, float $percentile): float
    {
        $index = ($percentile / 100) * (count($values) - 1);
        if (floor($index) == $index) {
            return $values[$index];
        }
        $lower = floor($index);
        $upper = ceil($index);
        $fraction = $index - $lower;
        return $values[$lower] + $fraction * ($values[$upper] - $values[$lower]);
    }

    private function initializeAggregators(): void
    {
        $this->aggregators = [
            'count' => function($metrics) {
                return count($metrics);
            },
            'sum' => function($metrics) {
                return array_sum(array_column($metrics, 'value'));
            },
            'average' => function($metrics) {
                $values = array_column($metrics, 'value');
                return !empty($values) ? array_sum($values) / count($values) : 0;
            },
            'min' => function($metrics) {
                $values = array_column($metrics, 'value');
                return !empty($values) ? min($values) : 0;
            },
            'max' => function($metrics) {
                $values = array_column($metrics, 'value');
                return !empty($values) ? max($values) : 0;
            },
            'rate' => function($metrics, $options) {
                $timespan = $options['timespan'] ?? 60;
                $count = count($metrics);
                $timeRange = max(array_column($metrics, 'timestamp')) - 
                            min(array_column($metrics, 'timestamp'));
                return $timeRange > 0 ? ($count / $timeRange) * $timespan : 0;
            }
        ];
    }
}
