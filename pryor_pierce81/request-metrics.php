<?php

namespace App\Core\Monitoring\Request\Metrics;

class RequestMetricsCollector {
    private array $collectors;
    private MetricsAggregator $aggregator;
    private MetricsStorage $storage;

    public function collect(Request $request): array 
    {
        $metrics = [];

        foreach ($this->collectors as $collector) {
            $metrics = array_merge(
                $metrics,
                $collector->collect($request)
            );
        }

        $aggregated = $this->aggregator->aggregate($metrics);
        $this->storage->store($aggregated);

        return $aggregated;
    }
}

class ResponseTimeCollector implements MetricsCollector {
    public function collect(Request $request): array 
    {
        $startTime = $request->getStartTime();
        $endTime = microtime(true);

        return [
            'response_time' => [
                'total' => $endTime - $startTime,
                'php' => $this->getPhpTime(),
                'db' => $this->getDatabaseTime(),
                'cache' => $this->getCacheTime()
            ]
        ];
    }
}

class ResourceUsageCollector implements MetricsCollector {
    public function collect(Request $request): array 
    {
        return [
            'resource_usage' => [
                'memory' => [
                    'peak' => memory_get_peak_usage(true),
                    'current' => memory_get_usage(true)
                ],
                'cpu' => $this->getCpuUsage(),
                'files' => $this->getOpenFiles()
            ]
        ];
    }
}

class DatabaseMetricsCollector implements MetricsCollector {
    public function collect(Request $request): array 
    {
        return [
            'database' => [
                'queries' => $this->getQueryCount(),
                'time' => $this->getQueryTime(),
                'types' => $this->getQueryTypes()
            ]
        ];
    }
}

class CacheMetricsCollector implements MetricsCollector {
    public function collect(Request $request): array 
    {
        return [
            'cache' => [
                'hits' => $this->getHitCount(),
                'misses' => $this->getMissCount(),
                'keys' => $this->getAccessedKeys()
            ]
        ];
    }
}

class MetricsAggregator {
    private array $rules;

    public function aggregate(array $metrics): array 
    {
        $aggregated = [];

        foreach ($this->rules as $rule) {
            $aggregated = array_merge(
                $aggregated,
                $rule->apply($metrics)
            );
        }

        return $aggregated;
    }
}

interface AggregationRule {
    public function apply(array $metrics): array;
}

class AverageAggregator implements AggregationRule {
    public function apply(array $metrics): array 
    {
        $result = [];

        foreach ($metrics as $key => $values) {
            if (is_array($values) && $this->isNumericArray($values)) {
                $result[$key . '_avg'] = array_sum($values) / count($values);
            }
        }

        return $result;
    }

    private function isNumericArray(array $array): bool 
    {
        return count(array_filter($array, 'is_numeric')) === count($array);
    }
}

class PercentileAggregator implements AggregationRule {
    private array $percentiles;

    public function __construct(array $percentiles = [50, 90, 95, 99]) 
    {
        $this->percentiles = $percentiles;
    }

    public function apply(array $metrics): array 
    {
        $result = [];

        foreach ($metrics as $key => $values) {
            if (is_array($values) && $this->isNumericArray($values)) {
                foreach ($this->percentiles as $p) {
                    $result[$key . '_p' . $p] = $this->calculatePercentile($values, $p);
                }
            }
        }

        return $result;
    }

    private function calculatePercentile(array $values, float $percentile): float 
    {
        sort($values);
        $index = ceil(($percentile / 100) * count($values)) - 1;
        return $values[$index];
    }
}

class RateAggregator implements AggregationRule {
    public function apply(array $metrics): array 
    {
        $result = [];

        foreach ($metrics as $key => $values) {
            if (is_array($values) && isset($values['count'], $values['duration'])) {
                $result[$key . '_rate'] = $values['count'] / $values['duration'];
            }
        }

        return $result;
    }
}
