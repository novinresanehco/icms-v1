<?php

namespace App\Core\Monitoring;

use Illuminate\Support\Facades\{Redis, Log};
use App\Core\Contracts\MonitoringInterface;

class MetricsCollector implements MonitoringInterface
{
    private const METRIC_PREFIX = 'metrics:';
    private const ALERT_PREFIX = 'alerts:';
    private const TRACE_PREFIX = 'traces:';

    private array $config;
    private array $thresholds;
    private AlertManager $alerts;

    public function __construct(AlertManager $alerts, array $config)
    {
        $this->alerts = $alerts;
        $this->config = $config;
        $this->thresholds = $config['thresholds'] ?? [];
    }

    public function record(string $metric, $value, array $tags = []): void
    {
        $timestamp = microtime(true);
        $key = $this->getMetricKey($metric);

        Redis::pipeline(function($pipe) use ($key, $value, $tags, $timestamp) {
            // Store raw metric value
            $pipe->zadd(
                $key, 
                $timestamp, 
                json_encode([
                    'value' => $value,
                    'tags' => $tags,
                    'timestamp' => $timestamp
                ])
            );

            // Update aggregates
            $this->updateAggregates($pipe, $key, $value, $timestamp);

            // Check thresholds
            $this->checkThresholds($metric, $value, $tags);

            // Cleanup old data
            $this->cleanupOldData($pipe, $key, $timestamp);
        });
    }

    public function incrementCounter(string $metric, int $value = 1, array $tags = []): void
    {
        $key = $this->getMetricKey($metric);
        
        Redis::pipeline(function($pipe) use ($key, $value, $tags) {
            $pipe->incrby($key, $value);
            $pipe->hset($key . ':tags', json_encode($tags), $value);
        });
    }

    public function startTrace(string $operation): string
    {
        $traceId = $this->generateTraceId();
        $timestamp = microtime(true);

        Redis::hset(
            $this->getTraceKey($traceId),
            'operation', $operation,
            'start_time', $timestamp,
            'status', 'running'
        );

        return $traceId;
    }

    public function endTrace(string $traceId, ?string $status = null): void
    {
        $traceKey = $this->getTraceKey($traceId);
        $startTime = Redis::hget($traceKey, 'start_time');
        
        if (!$startTime) {
            return;
        }

        $duration = microtime(true) - (float)$startTime;
        $operation = Redis::hget($traceKey, 'operation');

        Redis::pipeline(function($pipe) use ($traceKey, $duration, $status, $operation) {
            $pipe->hset($traceKey, 'duration', $duration);
            $pipe->hset($traceKey, 'status', $status ?? 'completed');
            
            // Record duration metric
            $this->record("operation.duration.{$operation}", $duration);
        });
    }

    public function recordError(string $operation, \Throwable $error, array $context = []): void
    {
        $errorData = [
            'operation' => $operation,
            'error' => [
                'type' => get_class($error),
                'message' => $error->getMessage(),
                'code' => $error->getCode(),
                'file' => $error->getFile(),
                'line' => $error->getLine()
            ],
            'context' => $context,
            'timestamp' => microtime(true)
        ];

        // Store error details
        $key = $this->getErrorKey($operation);
        Redis::zadd($key, $errorData['timestamp'], json_encode($errorData));

        // Update error counters
        $this->incrementCounter("errors.{$operation}");
        $this->incrementCounter("errors.total");

        // Check error thresholds
        $this->checkErrorThresholds($operation);

        Log::error('Operation error', $errorData);
    }

    public function getMetrics(string $metric, $from = null, $to = null): array
    {
        $key = $this->getMetricKey($metric);
        $from = $from ?? '-inf';
        $to = $to ?? '+inf';

        return Redis::zrangebyscore($key, $from, $to);
    }

    public function getAggregates(string $metric, string $period = '1hour'): array
    {
        $key = $this->getMetricKey($metric) . ":agg:{$period}";
        return [
            'avg' => Redis::get($key . ':avg') ?? 0,
            'min' => Redis::get($key . ':min') ?? 0,
            'max' => Redis::get($key . ':max') ?? 0,
            'count' => Redis::get($key . ':count') ?? 0
        ];
    }

    protected function updateAggregates($pipe, string $key, $value, float $timestamp): void
    {
        foreach ($this->config['aggregation_periods'] as $period => $retention) {
            $aggKey = "{$key}:agg:{$period}";
            $bucketTs = $this->getBucketTimestamp($timestamp, $period);

            $pipe->zadd($aggKey, $bucketTs, $value);
            $pipe->zremrangebyscore($aggKey, '-inf', $timestamp - $retention);
        }
    }

    protected function checkThresholds(string $metric, $value, array $tags): void
    {
        if (isset($this->thresholds[$metric])) {
            $threshold = $this->thresholds[$metric];

            if ($value > $threshold['critical'] ?? PHP_FLOAT_MAX) {
                $this->alerts->critical($metric, $value, $tags);
            } elseif ($value > $threshold['warning'] ?? PHP_FLOAT_MAX) {
                $this->alerts->warning($metric, $value, $tags);
            }
        }
    }

    protected function checkErrorThresholds(string $operation): void
    {
        $errorRate = $this->calculateErrorRate($operation);
        $threshold = $this->thresholds['error_rate'] ?? 0.01;

        if ($errorRate > $threshold) {
            $this->alerts->critical('error_rate', $errorRate, ['operation' => $operation]);
        }
    }

    protected function calculateErrorRate(string $operation): float
    {
        $totalKey = $this->getMetricKey("operations.{$operation}.total");
        $errorKey = $this->getMetricKey("errors.{$operation}");

        $total = Redis::get($totalKey) ?? 0;
        $errors = Redis::get($errorKey) ?? 0;

        return $total > 0 ? $errors / $total : 0;
    }

    protected function cleanupOldData($pipe, string $key, float $timestamp): void
    {
        $retention = $this->config['retention_period'] ?? 86400;
        $pipe->zremrangebyscore($key, '-inf', $timestamp - $retention);
    }

    protected function getBucketTimestamp(float $timestamp, string $period): int
    {
        $intervals = [
            '1min' => 60,
            '5min' => 300,
            '1hour' => 3600,
            '1day' => 86400
        ];

        $interval = $intervals[$period] ?? 3600;
        return floor($timestamp / $interval) * $interval;
    }

    protected function getMetricKey(string $metric): string
    {
        return self::METRIC_PREFIX . $metric;
    }

    protected function getTraceKey(string $traceId): string
    {
        return self::TRACE_PREFIX . $traceId;
    }

    protected function getErrorKey(string $operation): string
    {
        return self::METRIC_PREFIX . "errors:{$operation}";
    }

    protected function generateTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
