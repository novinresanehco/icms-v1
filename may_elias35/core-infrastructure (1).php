<?php

namespace App\Core\Infrastructure;

use Illuminate\Support\Facades\{Cache, Log, Redis};
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Throwable;

class CacheManager
{
    private CacheContract $cache;
    private array $config;

    public function __construct(CacheContract $cache, array $config)
    {
        $this->cache = $cache;
        $this->config = $config;
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        try {
            return $this->cache->remember($key, $ttl, $callback);
        } catch (Throwable $e) {
            Log::error('Cache operation failed', ['key' => $key, 'error' => $e->getMessage()]);
            return $callback();
        }
    }

    public function forget(string $key): void
    {
        try {
            $this->cache->forget($key);
        } catch (Throwable $e) {
            Log::error('Cache clear failed', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }
}

class SystemMonitor
{
    private MetricsCollector $metrics;
    
    public function __construct(MetricsCollector $metrics)
    {
        $this->metrics = $metrics;
    }

    public function trackOperation(string $operation, callable $callback): mixed
    {
        $start = microtime(true);
        try {
            $result = $callback();
            $this->recordSuccess($operation, microtime(true) - $start);
            return $result;
        } catch (Throwable $e) {
            $this->recordFailure($operation, $e);
            throw $e;
        }
    }

    private function recordSuccess(string $operation, float $duration): void
    {
        $this->metrics->record([
            'operation' => $operation,
            'status' => 'success',
            'duration' => $duration,
            'memory' => memory_get_usage(true)
        ]);
    }

    private function recordFailure(string $operation, Throwable $e): void
    {
        $this->metrics->record([
            'operation' => $operation,
            'status' => 'failure',
            'error' => $e->getMessage(),
            'memory' => memory_get_usage(true)
        ]);
    }
}

class MetricsCollector
{
    private Redis $redis;

    public function __construct(Redis $redis)
    {
        $this->redis = $redis;
    }

    public function record(array $metrics): void
    {
        $key = 'metrics:' . ($metrics['operation'] ?? 'unknown');
        $this->redis->rpush($key, json_encode([
            ...$metrics,
            'timestamp' => microtime(true)
        ]));
    }

    public function getMetrics(string $operation, int $limit = 100): array
    {
        $key = "metrics:$operation";
        return array_map(
            fn($item) => json_decode($item, true),
            $this->redis->lrange($key, -$limit, -1)
        );
    }
}

class ErrorHandler
{
    private SystemMonitor $monitor;

    public function __construct(SystemMonitor $monitor)
    {
        $this->monitor = $monitor;
    }

    public function handle(Throwable $e): void
    {
        $severity = $this->getSeverity($e);
        
        Log::error($e->getMessage(), [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'severity' => $severity
        ]);

        if ($severity === 'critical') {
            $this->handleCritical($e);
        }
    }

    private function getSeverity(Throwable $e): string
    {
        return match(true) {
            $e instanceof SecurityException => 'critical',
            $e instanceof DatabaseException => 'critical',
            $e instanceof ValidationException => 'warning',
            default => 'error'
        };
    }

    private function handleCritical(Throwable $e): void
    {
        Cache::tags(['system_status'])->put('system_error', [
            'message' => $e->getMessage(),
            'time' => now(),
            'status' => 'critical'
        ], 3600);
    }
}

class HealthCheck
{
    private array $checks = [];
    private SystemMonitor $monitor;

    public function __construct(SystemMonitor $monitor)
    {
        $this->monitor = $monitor;
    }

    public function addCheck(string $name, callable $check): void
    {
        $this->checks[$name] = $check;
    }

    public function runChecks(): array
    {
        $results = [];
        foreach ($this->checks as $name => $check) {
            try {
                $results[$name] = [
                    'status' => $check() ? 'healthy' : 'unhealthy',
                    'timestamp' => now()
                ];
            } catch (Throwable $e) {
                $results[$name] = [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                    'timestamp' => now()
                ];
            }
        }
        return $results;
    }
}
