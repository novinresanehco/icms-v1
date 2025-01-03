<?php

namespace App\Core\Monitoring;

class PerformanceMonitor implements MonitorInterface
{
    protected MetricsCollector $metrics;
    protected CacheManager $cache;
    protected ConfigManager $config;
    protected array $thresholds;

    public function recordOperation(string $operation, float $duration): void
    {
        $this->metrics->record('operation.duration', $duration, [
            'operation' => $operation,
            'timestamp' => microtime(true)
        ]);

        $this->checkThreshold($operation, $duration);
    }

    public function trackMemory(string $operation): void
    {
        $usage = memory_get_peak_usage(true);
        
        $this->metrics->record('memory.usage', $usage, [
            'operation' => $operation,
            'timestamp' => microtime(true)
        ]);

        if ($usage > $this->thresholds['memory']) {
            $this->reportHighMemoryUsage($operation, $usage);
        }
    }

    public function trackCache(string $key, bool $hit): void
    {
        $metric = $hit ? 'cache.hit' : 'cache.miss';
        
        $this->metrics->increment($metric, [
            'key' => $key,
            'timestamp' => microtime(true)
        ]);

        $this->updateCacheStats($key, $hit);
    }

    protected function checkThreshold(string $operation, float $duration): void
    {
        $threshold = $this->thresholds[$operation] ?? $this->thresholds['default'];
        
        if ($duration > $threshold) {
            $this->reportSlowOperation($operation, $duration, $threshold);
        }
    }

    protected function updateCacheStats(string $key, bool $hit): void
    {
        $stats = $this->cache->remember("cache_stats:$key", function() {
            return [
                'hits' => 0,
                'misses' => 0,
                'ratio' => 0
            ];
        });

        $hit ? $stats['hits']++ : $stats['misses']++;
        $stats['ratio'] = $stats['hits'] / ($stats['hits'] + $stats['misses']);

        $this->cache->put("cache_stats:$key", $stats, 3600);
    }

    protected function reportSlowOperation(string $operation, float $duration, float $threshold): void
    {
        Log::warning('Slow operation detected', [
            'operation' => $operation,
            'duration' => $duration,
            'threshold' => $threshold,
            'timestamp' => microtime(true)
        ]);
    }

    protected function reportHighMemoryUsage(string $operation, int $usage): void
    {
        Log::warning('High memory usage detected', [
            'operation' => $operation,
            'usage' => $usage,
            'threshold' => $this->thresholds['memory'],
            'timestamp' => microtime(true)
        ]);
    }
}
