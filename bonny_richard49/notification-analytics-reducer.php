<?php

namespace App\Core\Notification\Analytics\Reducer;

class DataReducer
{
    private array $reducers = [];
    private array $metrics = [];
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'combine_threshold' => 100,
            'timeout' => 30
        ], $config);
    }

    public function addReducer(string $name, ReducerInterface $reducer): void
    {
        $this->reducers[$name] = $reducer;
    }

    public function reduce(array $data, string $reducerName, array $options = []): array
    {
        if (!isset($this->reducers[$reducerName])) {
            throw new \InvalidArgumentException("Unknown reducer: {$reducerName}");
        }

        $startTime = microtime(true);
        try {
            $result = $this->reducers[$reducerName]->reduce($data, array_merge($this->config, $options));
            $this->recordMetrics($reducerName, $data, $result, microtime(true) - $startTime, true);
            return $result;
        } catch (\Exception $e) {
            $this->recordMetrics($reducerName, $data, [], microtime(true) - $startTime, false);
            throw $e;
        }
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    private function recordMetrics(string $reducerName, array $input, array $output, float $duration, bool $success): void
    {
        if (!isset($this->metrics[$reducerName])) {
            $this->metrics[$reducerName] = [
                'total_operations' => 0,
                'successful_operations' => 0,
                'failed_operations' => 0,
                'total_duration' => 0,
                'input_size' => 0,
                'output_size' => 0,
                'reduction_ratio' => 0
            ];
        }

        $metrics = &$this->metrics[$reducerName];
        $metrics['total_operations']++;
        $metrics[$success ? 'successful_operations' : 'failed_operations']++;
        $metrics['total_duration'] += $duration;
        $metrics['input_size'] += count($input);
        $metrics['output_size'] += count($output);
        $metrics['reduction_ratio'] = $metrics['input_size'] > 0 ? 
            1 - ($metrics['output_size'] / $metrics['input_size']) : 0;
    }
}

interface ReducerInterface
{
    public function reduce(array $data, array $options = []): array;
}

class SummaryReducer implements ReducerInterface
{
    public function reduce(array $data, array $options = []): array
    {
        $fields = $options['fields'] ?? array_keys(reset($data) ?: []);
        $summaries = [];

        foreach ($fields as $field) {
            $summaries[$field] = $this->calculateFieldSummary($data, $field);
        }

        return $summaries;
    }

    private function calculateFieldSummary(array $data, string $field): array
    {
        $values = array_column($data, $field);
        $numericValues = array_filter($values, 'is_numeric');

        $summary = [
            'count' => count($values),
            'unique' => count(array_unique($values))
        ];

        if (!empty($numericValues)) {
            $summary = array_merge($summary, [
                'min' => min($numericValues),
                'max' => max($numericValues),
                'sum' => array_sum($numericValues),
                'avg' => array_sum($numericValues) / count($numericValues)
            ]);
        }

        return $summary;
    }
}

class FrequencyReducer implements ReducerInterface
{
    public function reduce(array $data, array $options = []): array
    {
        $fields = $options['fields'] ?? array_keys(reset($data) ?: []);
        $frequencies = [];

        foreach ($fields as $field) {
            $frequencies[$field] = $this->calculateFieldFrequency($data, $field);
        }

        return $frequencies;
    }

    private function calculateFieldFrequency(array $data, string $field): array
    {
        $frequencies = [];
        foreach ($data as $item) {
            $value = $item[$field] ?? null;
            if ($value !== null) {
                $key = is_array($value) ? json_encode($value) : (string)$value;
                $frequencies[$key] = ($frequencies[$key] ?? 0) + 1;
            }
        }

        arsort($frequencies);
        return $frequencies;
    }
}

class DistributionReducer implements ReducerInterface
{
    public function reduce(array $data, array $options = []): array
    {
        $field = $options['field'] ?? null;
        if (!$field) {
            throw new \InvalidArgumentException("Field must be specified for distribution");
        }

        $values = array_column($data, $field);
        $numericValues = array_filter($values, 'is_numeric');

        if (empty($numericValues)) {
            return [];
        }

        sort($numericValues);
        $count = count($numericValues);

        return [
            'min' => $numericValues[0],
            'max' => $numericValues[$count - 1],
            'median' => $this->calculateMedian($numericValues),
            'quartiles' => $this->calculateQuartiles($numericValues),
            'percentiles' => $this->calculatePercentiles($numericValues),
            'std_dev' => $this->calculateStdDev($numericValues)
        ];
    }

    private function calculateMedian(array $values): float
    {
        $count = count($values);
        $middle = floor($count / 2);

        if ($count % 2 == 0) {
            return ($values[$middle - 1] + $values[$middle]) / 2;
        }

        return $values[$middle];
    }

    private function calculateQuartiles(array $values): array
    {
        $count = count($values);
        return [
            'q1' => $values[floor($count / 4)],
            'q2' => $this->calculateMedian($values),
            'q3' => $values[floor($count * 3 / 4)]
        ];
    }

    private function calculatePercentiles(array $values): array
    {
        $count = count($values);
        $percentiles = [];
        for ($i = 5; $i <= 95; $i += 5) {
            $percentiles["p{$i}"] = $values[floor($count * $i / 100)];
        }
        return $percentiles;
    }

    private function calculateStdDev(array $values): float
    {
        $count = count($values);
        $mean = array_sum($values) / $count;
        $variance = 0;

        foreach ($values as $value) {
            $variance += pow($value - $mean, 2);
        }

        return sqrt($variance / $count);
    }
}
