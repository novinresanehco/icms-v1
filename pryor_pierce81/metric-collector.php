<?php

namespace App\Core\Monitoring\Metrics;

class MetricCollector {
    private array $collectors;
    private MetricStorage $storage;
    private Aggregator $aggregator;
    private RateCalculator $rateCalculator;
    private Normalizer $normalizer;

    public function __construct(
        array $collectors,
        MetricStorage $storage,
        Aggregator $aggregator,
        RateCalculator $rateCalculator,
        Normalizer $normalizer
    ) {
        $this->collectors = $collectors;
        $this->storage = $storage;
        $this->aggregator = $aggregator;
        $this->rateCalculator = $rateCalculator;
        $this->normalizer = $normalizer;
    }

    public function collect(): MetricCollection 
    {
        $metrics = [];

        foreach ($this->collectors as $collector) {
            $rawMetrics = $collector->collect();
            $normalizedMetrics = $this->normalizer->normalize($rawMetrics);
            $metrics = array_merge($metrics, $normalizedMetrics);
        }

        $aggregated = $this->aggregator->aggregate($metrics);
        $rates = $this->rateCalculator->calculate($metrics);
        
        $this->storage->store($metrics);

        return new MetricCollection($metrics, $aggregated, $rates);
    }
}

class TimeSeriesCollector implements MetricCollector {
    private DataSource $dataSource;
    private array $series;
    private array $intervals;

    public function collect(): array 
    {
        $metrics = [];

        foreach ($this->series as $series) {
            foreach ($this->intervals as $interval) {
                $data = $this->dataSource->fetch($series, $interval);
                $metrics[$series][$interval] = $this->processData($data);
            }
        }

        return $metrics;
    }

    private function processData(array $data): array 
    {
        return [
            'values' => $data,
            'min' => min($data),
            'max' => max($data),
            'avg' => array_sum($data) / count($data),
            'count' => count($data)
        ];
    }
}

class CounterCollector implements MetricCollector {
    private CounterStore $store;
    private array $counters;

    public function collect(): array 
    {
        $metrics = [];

        foreach ($this->counters as $counter) {
            $value = $this->store->get($counter);
            $delta = $this->store->getDelta($counter);
            
            $metrics[$counter] = [
                'value' => $value,
                'delta' => $delta,
                'rate' => $delta / 60 // per minute rate
            ];
        }

        return $metrics;
    }
}

class GaugeCollector implements MetricCollector {
    private array $gauges;
    private ValueProvider $provider;

    public function collect(): array 
    {
        $metrics = [];

        foreach ($this->gauges as $gauge) {
            $value = $this->provider->getValue($gauge);
            $metrics[$gauge] = [
                'value' => $value,
                'timestamp' => microtime(true)
            ];
        }

        return $metrics;
    }
}

class Aggregator {
    private array $functions;
    private array $dimensions;

    public function aggregate(array $metrics): array 
    {
        $aggregations = [];

        foreach ($this->dimensions as $dimension) {
            $aggregations[$dimension] = $this->aggregateByDimension($metrics, $dimension);
        }

        foreach ($this->functions as $function) {
            $aggregations[$function] = $this->aggregateByFunction($metrics, $function);
        }

        return $aggregations;
    }

    private function aggregateByDimension(array $metrics, string $dimension): array 
    {
        $grouped = [];

        foreach ($metrics as $metric) {
            $key = $metric[$dimension] ?? 'unknown';
            if (!isset($grouped[$key])) {
                $grouped[$key] = [];
            }
            $grouped[$key][] = $metric;
        }

        return array_map([$this, 'calculateMetrics'], $grouped);
    }

    private function aggregateByFunction(array $metrics, string $function): array 
    {
        switch ($function) {
            case 'sum':
                return $this->sum($metrics);
            case 'avg':
                return $this->average($metrics);
            case 'percentile':
                return $this->percentile($metrics);
            default:
                throw new \InvalidArgumentException("Unknown aggregation function: {$function}");
        }
    }
}

class RateCalculator {
    private array $windows = [60, 300, 900]; // 1min, 5min, 15min

    public function calculate(array $metrics): array 
    {
        $rates = [];

        foreach ($metrics as $name => $metric) {
            if (!isset($metric['values'])) {
                continue;
            }

            $rates[$name] = [];
            foreach ($this->windows as $window) {
                $rates[$name][$window] = $this->calculateRate($metric['values'], $window);
            }
        }

        return $rates;
    }

    private function calculateRate(array $values, int $window): float 
    {
        $recent = array_slice($values, -$window);
        if (empty($recent)) {
            return 0.0;
        }

        return (max($recent) - min($recent)) / count($recent);
    }
}

class Normalizer {
    private array $rules;
    private array $transforms;

    public function normalize(array $metrics): array 
    {
        $normalized = [];

        foreach ($metrics as $name => $metric) {
            $normalized[$name] = $this->normalizeMetric($metric);
        }

        return $normalized;
    }

    private function normalizeMetric(array $metric): array 
    {
        foreach ($this->rules as $rule) {
            if ($rule->applies($metric)) {
                $metric = $rule->apply($metric);
            }
        }

        foreach ($this->transforms as $transform) {
            $metric = $transform->transform($metric);
        }

        return $metric;
    }
}

class MetricCollection {
    private array $metrics;
    private array $aggregations;
    private array $rates;
    private float $timestamp;

    public function __construct(array $metrics, array $aggregations, array $rates) 
    {
        $this->metrics = $metrics;
        $this->aggregations = $aggregations;
        $this->rates = $rates;
        $this->timestamp = microtime(true);
    }

    public function toArray(): array 
    {
        return [
            'metrics' => $this->metrics,
            'aggregations' => $this->aggregations,
            'rates' => $this->rates,
            'timestamp' => $this->timestamp,
            'metadata' => [
                'metric_count' => count($this->metrics),
                'aggregation_count' => count($this->aggregations),
                'collected_at' => date('Y-m-d H:i:s', (int)$this->timestamp)
            ]
        ];
    }
}

