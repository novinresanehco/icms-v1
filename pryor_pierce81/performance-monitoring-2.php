<?php

namespace App\Core\Performance;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\Events\EventManager;

class PerformanceManager implements PerformanceInterface
{
    protected SecurityManager $security;
    protected EventManager $events;
    protected MetricsCollector $metrics;
    protected array $config;
    protected array $thresholds;
    private array $measurementStarts = [];

    public function __construct(
        SecurityManager $security,
        EventManager $events,
        MetricsCollector $metrics,
        array $config
    ) {
        $this->security = $security;
        $this->events = $events;
        $this->metrics = $metrics;
        $this->config = $config;
        $this->thresholds = $config['thresholds'];
    }

    public function startMeasurement(string $operation): string
    {
        $id = uniqid('perf_', true);
        $this->measurementStarts[$id] = [
            'operation' => $operation,
            'start_time' => hrtime(true),
            'memory_start' => memory_get_usage(true),
            'context' => $this->captureContext()
        ];
        return $id;
    }

    public function endMeasurement(string $id): void
    {
        if (!isset($this->measurementStarts[$id])) {
            throw new InvalidMeasurementException("No measurement found for ID: {$id}");
        }

        $start = $this->measurementStarts[$id];
        $endTime = hrtime(true);
        $endMemory = memory_get_usage(true);

        $metrics = [
            'operation' => $start['operation'],
            'duration' => ($endTime - $start['start_time']) / 1e9,
            'memory_peak' => memory_get_peak_usage(true),
            'memory_used' => $endMemory - $start['memory_start'],
            'context' => $start['context']
        ];

        $this->recordMetrics($metrics);
        $this->checkThresholds($metrics);
        unset($this->measurementStarts[$id]);
    }

    public function measureOperation(string $operation, callable $callback): mixed
    {
        $id = $this->startMeasurement($operation);
        
        try {
            return $callback();
        } finally {
            $this->endMeasurement($id);
        }
    }

    public function getMetrics(?string $operation = null): array
    {
        return $this->security->executeCriticalOperation(function() use ($operation) {
            return Cache::tags(['performance'])->remember(
                $this->getCacheKey($operation),
                function() use ($operation) {
                    return $this->metrics->getMetrics($operation);
                }
            );
        });
    }

    public function optimize(): void
    {
        $this->security->executeCriticalOperation(function() {
            DB::transaction(function() {
                $this->optimizeQueries();
                $this->optimizeCache();
                $this->optimizeMemory();
                $this->optimizeIndexes();
                Cache::tags(['performance'])->flush();
            });
        });
    }

    protected function recordMetrics(array $metrics): void
    {
        $this->metrics->record($metrics);
        
        $this->events->dispatch('performance.metric.recorded', [
            'metrics' => $metrics,
            'timestamp' => now()
        ]);
    }

    protected function checkThresholds(array $metrics): void
    {
        foreach ($this->thresholds as $metric => $threshold) {
            if (isset($metrics[$metric]) && $metrics[$metric] > $threshold) {
                $this->handleThresholdViolation($metric, $metrics[$metric], $threshold);
            }
        }
    }

    protected function handleThresholdViolation(string $metric, $value, $threshold): void
    {
        $this->events->dispatch('performance.threshold.exceeded', [
            'metric' => $metric,
            'value' => $value,
            'threshold' => $threshold,
            'timestamp' => now()
        ]);

        Log::warning("Performance threshold exceeded", [
            'metric' => $metric,
            'value' => $value,
            'threshold' => $threshold
        ]);

        if ($this->isAutomaticOptimizationEnabled($metric)) {
            $this->triggerOptimization($metric);
        }
    }

    protected function captureContext(): array
    {
        return [
            'request_id' => request()->id(),
            'user_id' => auth()->id(),
            'url' => request()->url(),
            'method' => request()->method(),
            'ip' => request()->ip(),
            'load' => sys_getloadavg(),
            'memory_total' => memory_get_usage(true),
            'queries' => DB::getQueryLog(),
            'cache_stats' => $this->getCacheStats()
        ];
    }

    protected function optimizeQueries(): void
    {
        $slowQueries = $this->metrics->getSlowQueries();
        
        foreach ($slowQueries as $query) {
            DB::statement("ANALYZE TABLE {$query['table']}");
        }
    }

    protected function optimizeCache(): void
    {
        $cacheStats = $this->getCacheStats();
        
        if ($cacheStats['hit_ratio'] < $this->config['min_cache_hit_ratio']) {
            Cache::tags(['performance'])->flush();
        }

        foreach ($this->config['cache_warm_up'] as $key => $callback) {
            Cache::remember($key, $callback);
        }
    }

    protected function optimizeMemory(): void
    {
        if (memory_get_usage(true) > $this->config['memory_threshold']) {
            gc_collect_cycles();
        }
    }

    protected function optimizeIndexes(): void
    {
        $tables = $this->getHighTrafficTables();
        
        foreach ($tables as $table) {
            DB::statement("OPTIMIZE TABLE {$table}");
        }
    }

    protected function getCacheStats(): array
    {
        $stats = Cache::getMemcached()->getStats();
        $serverStats = reset($stats);
        
        return [
            'hits' => $serverStats['get_hits'],
            'misses' => $serverStats['get_misses'],
            'hit_ratio' => $serverStats['get_hits'] / ($serverStats['get_hits'] + $serverStats['get_misses']),
            'items' => $serverStats['curr_items'],
            'bytes' => $serverStats['bytes']
        ];
    }

    protected function getHighTrafficTables(): array
    {
        return DB::select("
            SELECT table_name, table_rows
            FROM information_schema.tables
            WHERE table_schema = ?
            ORDER BY table_rows DESC
            LIMIT 10
        ", [config('database.connections.mysql.database')]);
    }

    protected function isAutomaticOptimizationEnabled(string $metric): bool
    {
        return in_array($metric, $this->config['auto_optimize_metrics']);
    }

    protected function triggerOptimization(string $metric): void
    {
        $method = "optimize" . ucfirst($metric);
        if (method_exists($this, $method)) {
            $this->$method();
        }
    }

    protected function getCacheKey(?string $operation): string
    {
        return "performance.metrics." . ($operation ?? 'all');
    }
}
