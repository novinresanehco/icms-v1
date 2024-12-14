<?php

namespace App\Core\Notification\Analytics\Aggregation;

class AnalyticsAggregator 
{
    private array $operations = [];
    private array $pipelines = [];
    private array $results = [];

    public function registerOperation(string $name, callable $operation): void
    {
        $this->operations[$name] = $operation;
    }

    public function createPipeline(string $name, array $steps): void
    {
        $this->pipelines[$name] = $steps;
    }

    public function aggregate(string $pipeline, array $data): array
    {
        if (!isset($this->pipelines[$pipeline])) {
            throw new \InvalidArgumentException("Pipeline not found: {$pipeline}");
        }

        $result = $data;
        foreach ($this->pipelines[$pipeline] as $step) {
            if (!isset($this->operations[$step])) {
                throw new \InvalidArgumentException("Operation not found: {$step}");
            }
            $result = ($this->operations[$step])($result);
        }

        $this->results[$pipeline] = $result;
        return $result;
    }

    public function groupByTimeInterval(array $data, string $interval): array
    {
        $grouped = [];
        foreach ($data as $item) {
            $timestamp = strtotime($item['timestamp']);
            $key = $this->getIntervalKey($timestamp, $interval);
            if (!isset($grouped[$key])) {
                $grouped[$key] = [];
            }
            $grouped[$key][] = $item;
        }
        return $grouped;
    }

    public function calculateMetrics(array $data, array $metrics): array
    {
        $results = [];
        foreach ($metrics as $metric => $config) {
            $results[$metric] = $this->calculateMetric($data, $config);
        }
        return $results;
    }

    public function applyFilters(array $data, array $filters): array
    {
        $filtered = $data;
        foreach ($filters as $field => $condition) {
            $filtered = array_filter($filtered, function($item) use ($field, $condition) {
                return $this->evaluateCondition($item[$field] ?? null, $condition);
            });
        }
        return array_values($filtered);
    }

    public function calculateTrends(array $data, array $config): array
    {
        $trends = [];
        foreach ($config as $metric => $params) {
            $trends[$metric] = $this->calculateTrend($data, $metric, $params);
        }
        return $trends;
    }

    private function calculateMetric(array $data, array $config): float
    {
        $values = array_map(function($item) use ($config) {
            return $item[$config['field']] ?? 0;
        }, $data);

        switch ($config['type']) {
            case 'sum':
                return array_sum($values);
            case 'avg':
                return !empty($values) ? array_sum($values) / count($values) : 0;
            case 'min':
                return !empty($values) ? min($values) : 0;
            case 'max':
                return !empty($values) ? max($values) : 0;
            case 'median':
                return $this->calculateMedian($values);
            default:
                throw new \InvalidArgumentException("Unknown metric type: {$config['type']}");
        }
    }

    private function calculateMedian(array $values): float
    {
        if (empty($values)) {
            return 0;
        }
        sort($values);
        $count = count($values);
        $middle = floor($count / 2);

        if ($count % 2 === 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        }

        return $values[$middle];
    }

    private function calculateTrend(array $data, string $metric, array $params): array
    {
        $values = array_map(function($item) use ($metric) {
            return $item[$metric] ?? 0;
        }, $data);

        $trend = [
            'direction' => $this->getTrendDirection($values),
            'slope' => $this->calculateSlope($values),
            'volatility' => $this->calculateVolatility($values)
        ];

        if ($params['include_forecast'] ?? false) {
            $trend['forecast'] = $this->generateForecast($values, $params['forecast_periods'] ?? 5);
        }

        return $trend;
    }

    private function getTrendDirection(array $values): string
    {
        if (empty($values)) {
            return 'stable';
        }

        $first = reset($values);
        $last = end($values);
        $difference = $last - $first;

        if (abs($difference) < 0.001) {
            return 'stable';
        }

        return $difference > 0 ? 'up' : 'down';
    }

    private function calculateSlope(array $values): float
    {
        $count = count($values);
        if ($count < 2) {
            return 0;
        }

        $x = range(0, $count - 1);
        $x_mean = array_sum($x) / $count;
        $y_mean = array_sum($values) / $count;

        $numerator = 0;
        $denominator = 0;

        for ($i = 0; $i < $count; $i++) {
            $numerator += ($x[$i] - $x_mean) * ($values[$i] - $y_mean);
            $denominator += ($x[$i] - $x_mean) ** 2;
        }

        return $denominator !== 0 ? $numerator / $denominator : 0;
    }

    private function calculateVolatility(array $values): float
    {
        $count = count($values);
        if ($count < 2) {
            return 0;
        }

        $mean = array_sum($values) / $count;
        $variance = 0;

        foreach ($values as $value) {
            $variance += ($value - $mean) ** 2;
        }

        return sqrt($variance / ($count - 1));
    }

    private function generateForecast(array $values, int $periods): array
    {
        $slope = $this->calculateSlope($values);
        $lastValue = end($values);
        $forecast = [];

        for ($i = 1; $i <= $periods; $i++) {
            $forecast[] = $lastValue + ($slope * $i);
        }

        return $forecast;
    }

    private function getIntervalKey(int $timestamp, string $interval): string
    {
        switch ($interval) {
            case 'hourly':
                return date('Y-m-d H:00:00', $timestamp);
            case 'daily':
                return date('Y-m-d', $timestamp);
            case 'weekly':
                return date('Y-W', $timestamp);
            case 'monthly':
                return date('Y-m', $timestamp);
            default:
                throw new \InvalidArgumentException("Invalid interval: {$interval}");
        }
    }

    private function evaluateCondition($value, array $condition): bool
    {
        $operator = $condition[0];
        $compareValue = $condition[1];

        switch ($operator) {
            case '=':
                return $value === $compareValue;
            case '>':
                return $value > $compareValue;
            case '<':
                return $value < $compareValue;
            case '>=':
                return $value >= $compareValue;
            case '<=':
                return $value <= $compareValue;
            case 'in':
                return in_array($value, $compareValue);
            case 'between':
                return $value >= $compareValue[0] && $value <= $compareValue[1];
            default:
                throw new \InvalidArgumentException("Unknown operator: {$operator}");
        }
    }
}
