<?php

namespace App\Core\Monitoring;

class MetricsManager implements MetricsManagerInterface 
{
    private Repository $repository;
    private CacheManager $cache;
    private AlertManager $alerts;
    private array $thresholds;

    public function __construct(
        Repository $repository,
        CacheManager $cache,
        AlertManager $alerts,
        array $thresholds = []
    ) {
        $this->repository = $repository;
        $this->cache = $cache;
        $this->alerts = $alerts;
        $this->thresholds = array_merge([
            'response_time' => 200,
            'error_rate' => 0.01,
            'memory_usage' => 80,
            'cpu_usage' => 70
        ], $thresholds);
    }

    public function recordMetric(string $type, $value, array $tags = []): void 
    {
        DB::beginTransaction();
        
        try {
            $metric = $this->repository->create([
                'type' => $type,
                'value' => $value,
                'tags' => $tags,
                'timestamp' => now(),
                'hash' => $this->generateMetricHash($type, $value, $tags)
            ]);

            $this->cache->tags(['metrics'])->put(
                $this->getCacheKey($type, $tags),
                $value,
                60
            );

            $this->checkThresholds($type, $value, $tags);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            throw new MetricsException('Failed to record metric', 0, $e);
        }
    }

    public function trackPerformance(callable $operation, string $name): mixed 
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        try {
            $result = $operation();

            $this->recordMetrics($name, [
                'execution_time' => microtime(true) - $startTime,
                'memory_usage' => (memory_get_usage(true) - $startMemory) / 1024 / 1024,
                'status' => 'success'
            ]);

            return $result;

        } catch (\Exception $e) {
            $this->recordMetrics($name, [
                'execution_time' => microtime(true) - $startTime,
                'memory_usage' => (memory_get_usage(true) - $startMemory) / 1024 / 1024,
                'status' => 'error',
                'error_type' => get_class($e)
            ]);

            throw $e;
        }
    }

    public function getMetrics(string $type, array $filters = []): array 
    {
        try {
            return $this->cache->tags(['metrics'])->remember(
                "metrics.{$type}." . md5(json_encode($filters)),
                60,
                fn() => $this->repository->getMetrics($type, $filters)
            );

        } catch (\Exception $e) {
            throw new MetricsException('Failed to retrieve metrics', 0, $e);
        }
    }

    public function calculateAggregates(string $type, string $interval = '1h'): array 
    {
        try {
            return $this->cache->tags(['metrics'])->remember(
                "aggregates.{$type}.{$interval}",
                60,
                function() use ($type, $interval) {
                    $metrics = $this->repository->getMetricsByInterval($type, $interval);
                    
                    return [
                        'average' => $this->calculateAverage($metrics),
                        'median' => $this->calculateMedian($metrics),
                        'percentile_95' => $this->calculatePercentile($metrics, 95),
                        'min' => min($metrics),
                        'max' => max($metrics),
                        'count' => count($metrics)
                    ];
                }
            );

        } catch (\Exception $e) {
            throw new MetricsException('Failed to calculate aggregates', 0, $e);
        }
    }

    public function analyzeTrends(string $type, string $interval = '24h'): array 
    {
        try {
            $current = $this->getMetrics($type, ['interval' => $interval]);
            $previous = $this->getMetrics($type, [
                'interval' => $interval,
                'offset' => $interval
            ]);

            return [
                'change_rate' => $this->calculateChangeRate($current, $previous),
                'trend_direction' => $this->determineTrend($current, $previous),
                'anomalies' => $this->detectAnomalies($current),
                'forecast' => $this->generateForecast($current)
            ];

        } catch (\Exception $e) {
            throw new MetricsException('Failed to analyze trends', 0, $e);
        }
    }

    private function recordMetrics(string $name, array $metrics): void 
    {
        foreach ($metrics as $type => $value) {
            $this->recordMetric(
                "{$name}.{$type}",
                $value,
                ['operation' => $name]
            );
        }
    }

    private function checkThresholds(string $type, $value, array $tags): void 
    {
        if (!isset($this->thresholds[$type])) {
            return;
        }

        if ($value > $this->thresholds[$type]) {
            $this->alerts->trigger(
                "threshold_exceeded.{$type}",
                [
                    'type' => $type,
                    'value' => $value,
                    'threshold' => $this->thresholds[$type],
                    'tags' => $tags
                ]
            );
        }
    }

    private function generateMetricHash(string $type, $value, array $tags): string 
    {
        return hash('sha256', json_encode([
            'type' => $type,
            'value' => $value,
            'tags' => $tags,
            'timestamp' => now()->timestamp
        ]));
    }

    private function getCacheKey(string $type, array $tags): string 
    {
        return "metrics.{$type}." . md5(json_encode($tags));
    }

    private function calculateAverage(array $metrics): float 
    {
        return array_sum($metrics) / count($metrics);
    }

    private function calculateMedian(array $metrics): float 
    {
        sort($metrics);
        $count = count($metrics);
        $middle = floor(($count - 1) / 2);

        if ($count % 2 == 0) {
            return ($metrics[$middle] + $metrics[$middle + 1]) / 2;
        }

        return $metrics[$middle];
    }

    private function calculatePercentile(array $metrics, int $percentile): float 
    {
        sort($metrics);
        $index = ceil(count($metrics) * $percentile / 100) - 1;
        return $metrics[$index];
    }
}
