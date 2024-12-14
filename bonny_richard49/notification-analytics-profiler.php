<?php

namespace App\Core\Notification\Analytics\Profiling;

use Illuminate\Support\Facades\Redis;
use App\Core\Monitoring\MetricsCollector;

class AnalyticsProfiler
{
    private MetricsCollector $metrics;
    private array $queryTimes = [];
    private array $memoryUsage = [];
    private int $startTime;
    private array $checkpoints = [];

    public function __construct(MetricsCollector $metrics)
    {
        $this->metrics = $metrics;
        $this->startTime = microtime(true);
    }

    public function startQueryProfile(string $queryId): void
    {
        $this->queryTimes[$queryId] = [
            'start' => microtime(true),
            'memory_start' => memory_get_usage(true)
        ];
    }

    public function endQueryProfile(string $queryId): array
    {
        if (!isset($this->queryTimes[$queryId])) {
            return [];
        }

        $end = microtime(true);
        $memoryEnd = memory_get_usage(true);
        $stats = [
            'duration' => $end - $this->queryTimes[$queryId]['start'],
            'memory_used' => $memoryEnd - $this->queryTimes[$queryId]['memory_start']
        ];

        $this->storeQueryMetrics($queryId, $stats);
        unset($this->queryTimes[$queryId]);

        return $stats;
    }

    public function checkpoint(string $name): void
    {
        $this->checkpoints[$name] = [
            'time' => microtime(true) - $this->startTime,
            'memory' => memory_get_usage(true)
        ];
    }

    public function measureMemory(string $operation): void
    {
        $this->memoryUsage[$operation] = [
            'peak' => memory_get_peak_usage(true),
            'current' => memory_get_usage(true)
        ];
    }

    public function getProfileReport(): array
    {
        return [
            'total_time' => microtime(true) - $this->startTime,
            'peak_memory' => memory_get_peak_usage(true),
            'checkpoints' => $this->checkpoints,
            'memory_usage' => $this->memoryUsage,
            'query_metrics' => $this->getQueryMetrics()
        ];
    }

    private function storeQueryMetrics(string $queryId, array $stats): void
    {
        $key = "analytics:query_metrics:{$queryId}";
        
        Redis::pipeline(function ($pipe) use ($key, $stats) {
            $pipe->hIncrByFloat($key, 'total_time', $stats['duration']);
            $pipe->hIncrBy($key, 'executions', 1);
            $pipe->hIncrBy($key, 'total_memory', $stats['memory_used']);
            $pipe->expire($key, 86400);
        });

        $this->metrics->record("analytics.query.{$queryId}.duration", $stats['duration']);
        $this->metrics->record("analytics.query.{$queryId}.memory", $stats['memory_used']);
    }

    private function getQueryMetrics(): array
    {
        $metrics = [];
        $pattern = "analytics:query_metrics:*";
        $keys = Redis::keys($pattern);

        foreach ($keys as $key) {
            $queryId = str_replace("analytics:query_metrics:", "", $key);
            $data = Redis::hGetAll($key);
            
            if (!empty($data)) {
                $metrics[$queryId] = [
                    'avg_time' => $data['total_time'] / $data['executions'],
                    'total_time' => (float)$data['total_time'],
                    'executions' => (int)$data['executions'],
                    'avg_memory' => $data['total_memory'] / $data['executions'],
                    'total_memory' => (int)$data['total_memory']
                ];
            }
        }

        return $metrics;
    }
}
