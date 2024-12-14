<?php

namespace App\Services;

use App\Interfaces\SecurityServiceInterface;
use Illuminate\Support\Facades\{DB, Cache, Log, Redis};
use Illuminate\Support\Carbon;
use App\Exceptions\MonitoringException;

class MonitoringService
{
    private SecurityServiceInterface $security;
    private array $metrics = [];
    private array $thresholds = [
        'response_time' => 200,  // ms
        'memory_usage' => 128,   // MB
        'cpu_load' => 70,        // %
        'error_rate' => 1        // %
    ];

    public function __construct(SecurityServiceInterface $security)
    {
        $this->security = $security;
    }

    public function recordMetric(string $key, $value, array $tags = []): void
    {
        $this->security->validateSecureOperation(
            fn() => $this->executeRecordMetric($key, $value, $tags),
            ['action' => 'monitoring.write']
        );
    }

    private function executeRecordMetric(string $key, $value, array $tags): void
    {
        $timestamp = Carbon::now()->timestamp;
        $metricData = [
            'value' => $value,
            'timestamp' => $timestamp,
            'tags' => $tags
        ];

        Redis::zadd("metrics:{$key}", $timestamp, json_encode($metricData));
        $this->checkThreshold($key, $value);
    }

    public function trackPerformance(callable $operation, string $context): mixed
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        try {
            $result = $operation();
            $this->recordOperationMetrics($context, $startTime, $startMemory);
            return $result;
        } catch (\Throwable $e) {
            $this->recordError($context, $e);
            throw $e;
        }
    }

    public function getMetrics(string $key, Carbon $startTime = null, Carbon $endTime = null): array
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeGetMetrics($key, $startTime, $endTime),
            ['action' => 'monitoring.read']
        );
    }

    private function executeGetMetrics(string $key, ?Carbon $startTime, ?Carbon $endTime): array
    {
        $start = $startTime ? $startTime->timestamp : '-inf';
        $end = $endTime ? $endTime->timestamp : '+inf';

        $rawMetrics = Redis::zrangebyscore("metrics:{$key}", $start, $end);
        return array_map(fn($metric) => json_decode($metric, true), $rawMetrics);
    }

    public function getSystemHealth(): array
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeGetSystemHealth(),
            ['action' => 'monitoring.health']
        );
    }

    private function executeGetSystemHealth(): array
    {
        return [
            'cpu_usage' => $this->getCpuUsage(),
            'memory_usage' => $this->getMemoryUsage(),
            'disk_space' => $this->getDiskSpace(),
            'database_status' => $this->getDatabaseStatus(),
            'cache_status' => $this->getCacheStatus(),
            'queue_status' => $this->getQueueStatus(),
            'error_rate' => $this->getErrorRate()
        ];
    }

    public function getPerformanceReport(Carbon $startTime, Carbon $endTime): array
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeGetPerformanceReport($startTime, $endTime),
            ['action' => 'monitoring.report']
        );
    }

    private function executeGetPerformanceReport(Carbon $startTime, Carbon $endTime): array
    {
        return [
            'response_times' => $this->getAverageResponseTimes($startTime, $endTime),
            'error_rates' => $this->getErrorRates($startTime, $endTime),
            'resource_usage' => $this->getResourceUsage($startTime, $endTime),
            'database_metrics' => $this->getDatabaseMetrics($startTime, $endTime),
            'cache_metrics' => $this->getCacheMetrics($startTime, $endTime)
        ];
    }

    private function recordOperationMetrics(string $context, float $startTime, int $startMemory): void
    {
        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        $this->recordMetric('response_time', ($endTime - $startTime) * 1000, ['context' => $context]);
        $this->recordMetric('memory_usage', ($endMemory - $startMemory) / 1024 / 1024, ['context' => $context]);
        $this->recordMetric('cpu_load', $this->getCpuUsage(), ['context' => $context]);
    }

    private function recordError(string $context, \Throwable $error): void
    {
        $this->recordMetric('error_count', 1, [
            'context' => $context,
            'type' => get_class($error),
            'message' => $error->getMessage()
        ]);

        Log::error('Operation failed', [
            'context' => $context,
            'error' => $error->getMessage(),
            'trace' => $error->getTraceAsString()
        ]);
    }

    private function checkThreshold(string $key, $value): void
    {
        if (isset($this->thresholds[$key]) && $value > $this->thresholds[$key]) {
            Log::warning("Threshold exceeded for {$key}", [
                'value' => $value,
                'threshold' => $this->thresholds[$key]
            ]);

            event(new ThresholdExceededEvent($key, $value, $this->thresholds[$key]));
        }
    }

    private function getCpuUsage(): float
    {
        $load = sys_getloadavg();
        return $load[0] * 100;
    }

    private function getMemoryUsage(): float
    {
        return memory_get_usage(true) / 1024 / 1024;
    }

    private function getDiskSpace(): array
    {
        $path = storage_path();
        return [
            'free' => disk_free_space($path) / 1024 / 1024,
            'total' => disk_total_space($path) / 1024 / 1024
        ];
    }

    private function getDatabaseStatus(): array
    {
        try {
            DB::select('SELECT 1');
            $status = 'operational';
        } catch (\Throwable $e) {
            $status = 'error';
        }

        return [
            'status' => $status,
            'connections' => DB::table('information_schema.processlist')->count()
        ];
    }

    private function getCacheStatus(): array
    {
        try {
            Cache::get('health_check');
            $status = 'operational';
        } catch (\Throwable $e) {
            $status = 'error';
        }

        return [
            'status' => $status,
            'hits' => $this->getCacheHitRate()
        ];
    }

    private function getQueueStatus(): array
    {
        return [
            'pending' => Redis::llen('queues:default'),
            'failed' => DB::table('failed_jobs')->count()
        ];
    }

    private function getErrorRate(): float
    {
        $total = $this->getMetricSum('request_count', Carbon::now()->subHour());
        $errors = $this->getMetricSum('error_count', Carbon::now()->subHour());
        
        return $total > 0 ? ($errors / $total) * 100 : 0;
    }

    private function getMetricSum(string $key, Carbon $since): int
    {
        $metrics = $this->getMetrics($key, $since);
        return array_reduce($metrics, fn($sum, $metric) => $sum + $metric['value'], 0);
    }

    private function getCacheHitRate(): float
    {
        $hits = $this->getMetricSum('cache_hit', Carbon::now()->subHour());
        $misses = $this->getMetricSum('cache_miss', Carbon::now()->subHour());
        
        $total = $hits + $misses;
        return $total > 0 ? ($hits / $total) * 100 : 0;
    }
}
