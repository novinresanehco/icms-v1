<?php

namespace App\Core\Audit\Processors;

class DataProcessor
{
    private array $processors;
    private array $config;
    private LoggerInterface $logger;

    public function __construct(array $processors, array $config, LoggerInterface $logger)
    {
        $this->processors = $processors;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function process(array $data): array
    {
        $context = new ProcessingContext();
        
        foreach ($this->processors as $processor) {
            if ($processor->supports($data)) {
                try {
                    $data = $processor->process($data, $context);
                } catch (\Exception $e) {
                    $this->logger->error('Processing error', [
                        'processor' => get_class($processor),
                        'error' => $e->getMessage()
                    ]);
                    throw $e;
                }
            }
        }

        return $data;
    }
}

class ProcessingContext
{
    private array $state = [];
    private array $metadata = [];
    private array $metrics = [];

    public function setState(string $key, $value): void
    {
        $this->state[$key] = $value;
    }

    public function getState(string $key)
    {
        return $this->state[$key] ?? null;
    }

    public function setMetadata(string $key, $value): void
    {
        $this->metadata[$key] = $value;
    }

    public function getMetadata(string $key)
    {
        return $this->metadata[$key] ?? null;
    }

    public function addMetric(string $name, $value): void
    {
        if (!isset($this->metrics[$name])) {
            $this->metrics[$name] = [];
        }
        $this->metrics[$name][] = $value;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }
}

class StatisticalProcessor
{
    public function supports(array $data): bool
    {
        return !empty($data) && array_reduce(
            $data,
            fn($carry, $item) => $carry && is_numeric($item),
            true
        );
    }

    public function process(array $data, ProcessingContext $context): array
    {
        $count = count($data);
        $sum = array_sum($data);
        $mean = $sum / $count;

        sort($data);
        $median = $this->calculateMedian($data);
        $stdDev = $this->calculateStandardDeviation($data, $mean);

        $context->setMetadata('statistical_analysis', [
            'count' => $count,
            'sum' => $sum,
            'mean' => $mean,
            'median' => $median,
            'std_dev' => $stdDev
        ]);

        return $data;
    }

    private function calculateMedian(array $data): float
    {
        $count = count($data);
        $mid = floor($count / 2);

        if ($count % 2 == 0) {
            return ($data[$mid - 1] + $data[$mid]) / 2;
        }

        return $data[$mid];
    }

    private function calculateStandardDeviation(array $data, float $mean): float
    {
        $variance = array_reduce(
            $data,
            fn($carry, $item) => $carry + pow($item - $mean, 2),
            0
        ) / count($data);

        return sqrt($variance);
    }
}

class OutlierProcessor
{
    private float $threshold;

    public function __construct(float $threshold = 2.0)
    {
        $this->threshold = $threshold;
    }

    public function supports(array $data): bool
    {
        return !empty($data) && array_reduce(
            $data,
            fn($carry, $item) => $carry && is_numeric($item),
            true
        );
    }

    public function process(array $data, ProcessingContext $context): array
    {
        $mean = array_sum($data) / count($data);
        $stdDev = $this->calculateStandardDeviation($data, $mean);

        $outliers = [];
        $cleaned = [];

        foreach ($data as $value) {
            $zScore = abs(($value - $mean) / $stdDev);
            
            if ($zScore > $this->threshold) {
                $outliers[] = $value;
            } else {
                $cleaned[] = $value;
            }
        }

        $context->setMetadata('outliers', [
            'count' => count($outliers),
            'values' => $outliers
        ]);

        return $cleaned;
    }

    private function calculateStandardDeviation(array $data, float $mean): float
    {
        return sqrt(array_sum(array_map(
            fn($x) => pow($x - $mean, 2),
            $data
        )) / count($data));
    }
}

class AggregationProcessor
{
    private array $aggregations;

    public function __construct(array $aggregations)
    {
        $this->aggregations = $aggregations;
    }

    public function supports(array $data): bool
    {
        return !empty($data) && is_array(reset($data));
    }

    public function process(array $data, ProcessingContext $context): array
    {
        $results = [];

        foreach ($this->aggregations as $field => $type) {
            $values = array_column($data, $field);
            
            $results[$field] = match($type) {
                'sum' => array_sum($values),
                'avg' => array_sum($values) / count($values),
                'min' => min($values),
                'max' => max($values),
                'count' => count($values),
                default => null
            };
        }

        $context->setMetadata('aggregations', $results);
        return $data;
    }
}

class NormalizationProcessor
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function supports(array $data): bool
    {
        return !empty($data) && array_reduce(
            $data,
            fn($carry, $item) => $carry && is_numeric($item),
            true
        );
    }

    public function process(array $data, ProcessingContext $context): array
    {
        $min = min($data);
        $max = max($data);
        
        if ($min === $max) {
            return array_fill(0, count($data), 0);
        }

        $targetMin = $this->config['target_min'] ?? 0;
        $targetMax = $this->config['target_max'] ?? 1;
        $targetRange = $targetMax - $targetMin;
        
        $normalized = array_map(
            function($value) use ($min, $max, $targetMin, $targetRange) {
                return $targetMin + (($value - $min) / ($max - $min)) * $targetRange;
            },
            $data
        );

        $context->setMetadata('normalization', [
            'original_min' => $min,
            'original_max' => $max,
            'target_min' => $targetMin,
            'target_max' => $targetMax
        ]);

        return $normalized;
    }
}
