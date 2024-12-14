<?php

namespace App\Core\Infrastructure;

class SystemMonitor implements MonitorInterface
{
    private MetricsCollector $metrics;
    private PerformanceAnalyzer $analyzer;
    private AlertManager $alerts;

    public function monitor(): void
    {
        // Collect metrics
        $metrics = $this->metrics->collect();
        
        // Analyze performance
        $analysis = $this->analyzer->analyze($metrics);
        
        // Check thresholds
        foreach ($analysis as $metric => $value) {
            if ($this->isThresholdExceeded($metric, $value)) {
                $this->alerts->trigger(
                    new ThresholdExceeded($metric, $value)
                );
            }
        }
    }

    private function isThresholdExceeded(string $metric, $value): bool
    {
        return $value > $this->getThreshold($metric);
    }
}

class CacheManager implements CacheInterface  
{
    private CacheStore $store;
    private int $ttl;

    public function remember(string $key, \Closure $callback)
    {
        if ($cached = $this->store->get($key)) {
            return $cached;
        }

        $value = $callback();
        $this->store->put($key, $value, $this->ttl);
        
        return $value;
    }

    public function forget(string $key): void
    {
        $this->store->forget($key);
    }
}

class PerformanceAnalyzer
{
    private array $thresholds = [
        'response_time' => 200, // ms
        'memory_usage' => 128 * 1024 * 1024, // 128MB
        'cpu_usage' => 70, // percent
    ];

    public function analyze(array $metrics): array
    {
        return [
            'response_time' => $this->analyzeResponseTime($metrics),
            'memory_usage' => $this->analyzeMemoryUsage($metrics),
            'cpu_usage' => $this->analyzeCpuUsage($metrics)
        ];
    }
}
