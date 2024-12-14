<?php

namespace App\Core\Infrastructure;

use Illuminate\Support\Facades\{Cache, Log, DB};
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Support\Manager;

class InfrastructureManager
{
    private CacheManager $cache;
    private ErrorHandler $errors;
    private MonitoringService $monitor;
    private PerformanceTracker $performance;

    public function __construct(
        CacheManager $cache,
        ErrorHandler $errors,
        MonitoringService $monitor,
        PerformanceTracker $performance
    ) {
        $this->cache = $cache;
        $this->errors = $errors;
        $this->monitor = $monitor;
        $this->performance = $performance;
    }

    public function boot(): void
    {
        $this->monitor->startTracking();
        $this->performance->initializeTracking();
        $this->errors->register();
    }

    public function shutdown(): void
    {
        $this->monitor->stopTracking();
        $this->performance->saveMetrics();
    }
}

class CacheManager extends Manager
{
    private CacheContract $store;

    public function remember(string $key, callable $callback, int $ttl = 3600)
    {
        return $this->store->remember($key, $ttl, function() use ($callback) {
            $start = microtime(true);
            $result = $callback();
            $this->trackCacheOperation('remember', microtime(true) - $start);
            return $result;
        });
    }

    public function invalidate(string $pattern): void
    {
        $keys = $this->store->get('cache_keys', []);
        foreach ($keys as $key) {
            if (fnmatch($pattern, $key)) {
                $this->store->forget($key);
            }
        }
    }

    private function trackCacheOperation(string $operation, float $duration): void
    {
        $this->store->increment("cache_stats.{$operation}.count");
        $this->store->increment("cache_stats.{$operation}.time", $duration);
    }
}

class ErrorHandler
{
    private array $handlers = [];
    private MonitoringService $monitor;

    public function register(): void
    {
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
    }

    public function handleError(int $level, string $message, string $file, int $line): bool
    {
        $error = [
            'level' => $level,
            'message' => $message,
            'file' => $file,
            'line' => $line
        ];

        $this->monitor->trackError($error);
        Log::error('System error occurred', $error);

        return false;
    }

    public function handleException(\Throwable $e): void
    {
        $this->monitor->trackException($e);
        Log::error('Uncaught exception', [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    public function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $this->handleError(
                $error['type'],
                $error['message'],
                $error['file'],
                $error['line']
            );
        }
    }
}

class MonitoringService
{
    private MetricsCollector $metrics;
    
    public function startTracking(): void
    {
        DB::listen(function($query) {
            $this->metrics->trackQuery($query);
        });

        Cache::listen(function($event) {
            $this->metrics->trackCache($event);
        });
    }

    public function stopTracking(): void
    {
        $this->metrics->save();
    }

    public function trackError(array $error): void
    {
        $this->metrics->increment('errors', 1, [
            'type' => $error['level']
        ]);
    }

    public function trackException(\Throwable $e): void
    {
        $this->metrics->increment('exceptions', 1, [
            'type' => get_class($e)
        ]);
    }
}

class PerformanceTracker
{
    private array $metrics = [];
    private float $startTime;

    public function initializeTracking(): void
    {
        $this->startTime = microtime(true);
        $this->metrics = [
            'memory_peak' => 0,
            'query_count' => 0,
            'cache_hits' => 0,
            'cache_misses' => 0
        ];
    }

    public function trackQuery(string $sql, float $time): void
    {
        $this->metrics['query_count']++;
        $this->metrics['query_time'] = ($this->metrics['query_time'] ?? 0) + $time;
    }

    public function trackCache(string $operation, bool $hit): void
    {
        $hit ? $this->metrics['cache_hits']++ : $this->metrics['cache_misses']++;
    }

    public function saveMetrics(): void
    {
        $this->metrics['execution_time'] = microtime(true) - $this->startTime;
        $this->metrics['memory_peak'] = memory_get_peak_usage(true);
        
        Cache::put('performance_metrics', $this->metrics, now()->addDay());
    }
}
