<?php

namespace App\Core\Metrics;

use Illuminate\Support\Facades\Redis;
use App\Core\Contracts\MetricsCollectorInterface;
use App\Core\Exceptions\MetricsException;

class MetricsCollector implements MetricsCollectorInterface
{
    private ThresholdManager $thresholds;
    private AlertSystem $alerts;
    private array $config;

    private const METRICS_TTL = 86400; // 24 hours
    private const BATCH_SIZE = 1000;

    public function __construct(
        ThresholdManager $thresholds,
        AlertSystem $alerts,
        array $config
    ) {
        $this->thresholds = $thresholds;
        $this->alerts = $alerts;
        $this->config = $config;
    }

    public function recordMetric(string $type, string $name, $value, array $tags = []): void
    {
        $metricId = $this->generateMetricId();
        
        Redis::multi();
        try {
            $this->storeMetric($metricId, $type, $name, $value, $tags);
            $this->updateAggregates($type, $name, $value);
            $this->checkThresholds($type, $name, $value);
            Redis::exec();
        } catch (\Exception $e) {
            Redis::discard();
            $this->handleMetricFailure($e, $type, $name, $value);
        }
    }

    public function recordBatch(array $metrics): void
    {
        $batches = array_chunk($metrics, self::BATCH_SIZE);
        
        foreach ($batches as $batch) {
            Redis::multi();
            try {
                foreach ($batch as $metric) {
                    $this->validateMetric($metric);
                    $this->storeMetric(
                        $this->generateMetricId(),
                        $metric['type'],
                        $metric['name'],
                        $metric['value'],
                        $metric['tags'] ?? []
                    );
                }
                Redis::exec();
            } catch (\Exception $e) {
                Redis::discard();
                $this->handleBatchFailure($e, $batch);
            }
        }
    }

    public function getMetrics(
        string $type,
        string $name,
        int $startTime,
        int $endTime
    ): array {
        $metrics = $this->fetchMetrics($type, $name, $startTime, $endTime);
        return $this->processMetrics($metrics);
    }

    public function getAggregates(string $type, string $name): array
    {
        return [
            'count' => $this->getCount($type, $name),
            'sum' => $this->getSum($type, $name),
            'avg' => $this->getAverage($type, $name),
            'min' => $this->getMin($type, $name),
            'max' => $this->getMax($type, $name),
            'p95' => $this->getPercentile($type, $name, 95),
            'p99' => $this->getPercentile($type, $name, 99)
        ];
    }

    private function storeMetric(
        string $metricId,
        string $type,
        string $name,
        $value,
        array $tags
    ): void {
        $metric = [
            'id' => $metricId,
            'type' => $type,
            'name' => $name,
            'value' => $value,
            'tags' => json_encode($tags),
            'timestamp' => microtime(true)
        ];

        $key = "metrics:{$type}:{$name}:{$metricId}";
        Redis::hMSet($key, $metric);
        Redis::expire($key, self::METRICS_TTL);
        
        // Store in time series
        $score = microtime(true);
        Redis::zAdd("metrics:timeseries:{$type}:{$name}", $score, $metricId);
    }

    private function updateAggregates(string $type, string $name, $value): void
    {
        $key = "metrics:aggregates:{$type}:{$name}";
        
        Redis::hIncrBy($key, 'count', 1);
        Redis::hIncrByFloat($key, 'sum', $value);
        Redis::hSet($key, 'last_value', $value);
        
        Redis::zAdd("metrics:values:{$type}:{$name}", $value, $value);
    }

    private function checkThresholds(string $type, string $name, $value): void
    {
        $threshold = $this->thresholds->getThreshold($type, $name);
        
        if ($threshold && $value > $threshold['critical']) {
            $this->handleCriticalThreshold($type, $name, $value, $threshold);
        } elseif ($threshold && $value > $threshold['warning']) {
            $this->handleWarningThreshold($type, $name, $value, $threshold);
        }
    }

    private function handleCriticalThreshold(string $type, string $name, $value, array $threshold): void
    {
        $violation = [
            'type' => $type,
            'name' => $name,
            'value' => $value,
            'threshold' => $threshold['critical'],
            'timestamp' => microtime(true)
        ];

        $this->alerts->notifyCriticalViolation($violation);
        event(new CriticalThresholdExceeded($violation));
    }

    private function handleWarningThreshold(string $type, string $name, $value, array $threshold): void
    {
        $violation = [
            'type' => $type,
            'name' => $name,
            'value' => $value,
            'threshold' => $threshold['warning'],
            'timestamp' => microtime(true)
        ];

        $this->alerts->notifyWarningViolation($violation);
    }

    private function fetchMetrics(string $type, string $name, int $startTime, int $endTime): array
    {
        $metricIds = Redis::zRangeByScore(
            "metrics:timeseries:{$type}:{$name}",
            $startTime,
            $endTime
        );

        $metrics = [];
        foreach ($metricIds as $metricId) {
            $metric = Redis::hGetAll("metrics:{$type}:{$name}:{$metricId}");
            if ($metric) {
                $metrics[] = $metric;
            }
        }

        return $metrics;
    }

    private function processMetrics(array $metrics): array
    {
        $processed = [];
        foreach ($metrics as $metric) {
            $processed[] = [
                'value' => $metric['value'],
                'timestamp' => $metric['timestamp'],
                'tags' => json_decode($metric['tags'], true)
            ];
        }
        
        return $processed;
    }

    private function validateMetric(array $metric): void
    {
        if (!isset($metric['type'], $metric['name'], $metric['value'])) {
            throw new MetricsException('Invalid metric format');
        }

        if (!$this->isValidMetricType($metric['type'])) {
            throw new MetricsException('Invalid metric type');
        }
    }

    private function isValidMetricType(string $type): bool
    {
        return in_array($type, [
            'counter',
            'gauge',
            'histogram',
            'summary'
        ]);
    }

    private function generateMetricId(): string
    {
        return uniqid('metric_', true);
    }

    private function handleMetricFailure(\Exception $e, string $type, string $name, $value): void
    {
        Log::error('Failed to record metric', [
            'type' => $type,
            'name' => $name,
            'value' => $value,
            'error' => $e->getMessage()
        ]);

        throw new MetricsException(
            'Failed to record metric: ' . $e->getMessage(),
            0,
            $e
        );
    }

    private function handleBatchFailure(\Exception $e, array $batch): void
    {
        Log::error('Failed to record metric batch', [
            'batch_size' => count($batch),
            'error' => $e->getMessage()
        ]);

        throw new MetricsException(
            'Failed to record metric batch: ' . $e->getMessage(),
            0,
            $e
        );
    }
}

class ThresholdManager
{
    private array $thresholds;
    private RedisManager $redis;

    public function setThreshold(
        string $type,
        string $name,
        float $warning,
        float $critical
    ): void {
        $key = "thresholds:{$type}:{$name}";
        
        Redis::hMSet($key, [
            'warning' => $warning,
            'critical' => $critical,
            'updated_at' => time()
        ]);
    }

    public function getThreshold(string $type, string $name): ?array
    {
        $key = "thresholds:{$type}:{$name}";
        $threshold = Redis::hGetAll($key);
        
        return $threshold ?: null;
    }

    public function validateThresholds(array $metrics): bool
    {
        foreach ($metrics as $metric) {
            $threshold = $this->getThreshold($metric['type'], $metric['name']);
            if ($threshold && $metric['value'] > $threshold['critical']) {
                return false;
            }
        }
        return true;
    }
}
