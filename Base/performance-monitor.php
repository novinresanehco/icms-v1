<?php

namespace App\Core\Repositories\Performance;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class PerformanceMonitor
{
    protected array $metrics = [];
    protected array $thresholds;
    protected string $metricsPrefix = 'cms:metrics:';
    
    public function __construct(array $config = [])
    {
        $this->thresholds = $config['thresholds'] ?? [
            'query_time' => 1000, // milliseconds
            'memory_usage' => 100 * 1024 * 1024, // 100MB
            'cache_hit_ratio' => 0.8, // 80%
            'slow_query_percentage' => 0.1 // 10%
        ];
    }

    public function startOperation(string $operation): string
    {
        $operationId = uniqid('op_', true);
        $this->metrics[$operationId] = [
            'operation' => $operation,
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(true),
            'queries' => [],
            'cache_hits' => 0,
            'cache_misses' => 0
        ];
        return $operationId;
    }

    public function endOperation(string $operationId): array
    {
        if (!isset($this->metrics[$operationId])) {
            throw new \InvalidArgumentException("Invalid operation ID: {$operationId}");
        }

        $metrics = &$this->metrics[$operationId];
        $metrics['end_time'] = microtime(true);
        $metrics['end_memory'] = memory_get_usage(true);
        $metrics['duration'] = $metrics['end_time'] - $metrics['start_time'];
        $metrics['memory_peak'] = memory_get_peak_usage(true);
        $metrics['memory_used'] = $metrics['end_memory'] - $metrics['start_memory'];

        $this->analyzeMetrics($metrics);
        $this->storeMetrics($metrics);

        return $metrics;
    }

    public function recordQuery(string $operationId, array $queryInfo): void
    {
        if (isset($this->metrics[$operationId])) {
            $this->metrics[$operationId]['queries'][] = array_merge($queryInfo, [
                'timestamp' => microtime(true)
            ]);
        }
    }

    public function recordCacheOperation(string $operationId, bool $hit): void
    {
        if (isset($this->metrics[$operationId])) {
            $key = $hit ? 'cache_hits' : 'cache_misses';
            $this->metrics[$operationId][$key]++;
        }
    }

    protected function analyzeMetrics(array &$metrics): void
    {
        // Calculate query statistics
        $queryTimes = array_column($metrics['queries'], 'time');
        $metrics['query_stats'] = [
            'total_queries' => count($metrics['queries']),
            'total_time' => array_sum($queryTimes),
            'avg_time' => count($queryTimes) ? array_sum($queryTimes) / count($queryTimes) : 0,
            'slow_queries' => count(array_filter($queryTimes, fn($time) => 
                $time > $this->thresholds['query_time']
            ))
        ];

        // Calculate cache statistics
        $totalCacheOps = $metrics['cache_hits'] + $metrics['cache_misses'];
        $metrics['cache_stats'] = [
            'hit_ratio' => $totalCacheOps ? $metrics['cache_hits'] / $totalCacheOps : 1,
            'total_operations' => $totalCacheOps
        ];

        // Check for performance issues
        $metrics['issues'] = $this->detectPerformanceIssues($metrics);
    }

    protected function detectPerformanceIssues(array $metrics): array
    {
        $issues = [];

        // Check query performance
        if ($metrics['query_stats']['avg_time'] > $this->thresholds['query_time']) {
            $issues[] = [
                'type' => 'slow_queries',
                'message' => 'Average query time exceeds threshold',
                'value' => $metrics['query_stats']['avg_time'],
                'threshold' => $this->thresholds['query_time']
            ];
        }

        // Check memory usage
        if ($metrics['memory_peak'] > $this->thresholds['memory_usage']) {
            $issues[] = [
                'type' => 'high_memory',
                'message' => 'Peak memory usage exceeds threshold',
                'value' => $metrics['memory_peak'],
                'threshold' => $this->thresholds['memory_usage']
            ];
        }

        // Check cache effectiveness
        if ($metrics['cache_stats']['hit_ratio'] < $this->thresholds['cache_hit_ratio']) {
            $issues[] = [
                'type' => 'low_cache_hits',
                'message' => 'Cache hit ratio below threshold',
                'value' => $metrics['cache_stats']['hit_ratio'],
                'threshold' => $this->thresholds['cache_hit_ratio']
            ];
        }

        return $issues;
    }

    protected function storeMetrics(array $metrics): void
    {
        $key = $this->metricsPrefix . $metrics['operation'] . ':' . date('Y-m-d:H');
        
        Redis::pipeline(function ($pipe) use ($key, $metrics) {
            $pipe->hIncrBy($key, 'total_operations', 1);
            $pipe->hIncrByFloat($key, 'total_duration', $metrics['duration']);
            $pipe->hIncrBy($key, 'total_queries', $metrics['query_stats']['total_queries']);
            $pipe->hIncrBy($key, 'total_cache_hits', $metrics['cache_hits']);
            $pipe->hIncrBy($key, 'total_cache_misses', $metrics['cache_misses']);
            $pipe->hIncrByFloat($key, 'total_memory', $metrics['memory_used']);
            $pipe->expire($key, 86400 * 7); // Keep metrics for 7 days
        });

        // Log performance issues
        if (!empty($metrics['issues'])) {
            Log::warning('Performance issues detected', [
                'operation' => $metrics['operation'],
                'issues' => $metrics['issues']
            ]);
        }
    }
}
