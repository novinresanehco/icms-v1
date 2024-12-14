<?php

namespace App\Core\Infrastructure;

use Illuminate\Support\Facades\{Cache, Log, DB, Redis};
use App\Core\Security\SecurityManager;

class InfrastructureManager
{
    private SecurityManager $security;
    private CacheSystem $cache;
    private MonitoringService $monitor;
    private LoggingService $logger;

    public function __construct(
        SecurityManager $security,
        CacheSystem $cache,
        MonitoringService $monitor,
        LoggingService $logger
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->monitor = $monitor;
        $this->logger = $logger;
    }

    public function startCriticalOperation(string $operation): string
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->initializeOperation($operation),
            ['action' => 'start_operation']
        );
    }

    private function initializeOperation(string $operation): string
    {
        $id = bin2hex(random_bytes(16));
        $this->monitor->startTracking($id);
        $this->logger->logOperation($operation, $id);
        return $id;
    }
}

class CacheSystem
{
    private Redis $redis;

    public function __construct(Redis $redis)
    {
        $this->redis = $redis;
    }

    public function remember(string $key, $value, int $ttl = 3600)
    {
        $cached = $this->redis->get($key);
        
        if ($cached !== null) {
            return unserialize($cached);
        }

        $computed = is_callable($value) ? $value() : $value;
        $this->redis->setex($key, $ttl, serialize($computed));
        
        return $computed;
    }

    public function invalidate(string $key): void
    {
        $this->redis->del($key);
    }

    public function invalidatePattern(string $pattern): void
    {
        foreach ($this->redis->keys($pattern) as $key) {
            $this->redis->del($key);
        }
    }
}

class MonitoringService
{
    private array $operations = [];
    private MetricsCollector $metrics;

    public function __construct(MetricsCollector $metrics)
    {
        $this->metrics = $metrics;
    }

    public function startTracking(string $id): void
    {
        $this->operations[$id] = [
            'start_time' => microtime(true),
            'memory_start' => memory_get_usage(true),
            'queries_start' => DB::getQueryLog()
        ];
    }

    public function endTracking(string $id): array
    {
        if (!isset($this->operations[$id])) {
            throw new InfrastructureException('Operation not found');
        }

        $metrics = $this->calculateMetrics($id);
        $this->metrics->record($metrics);
        
        unset($this->operations[$id]);
        return $metrics;
    }

    private function calculateMetrics(string $id): array
    {
        $start = $this->operations[$id];
        
        return [
            'duration' => microtime(true) - $start['start_time'],
            'memory_used' => memory_get_usage(true) - $start['memory_start'],
            'queries_count' => count(DB::getQueryLog()) - count($start['queries_start']),
            'peak_memory' => memory_get_peak_usage(true)
        ];
    }
}

class MetricsCollector
{
    private Redis $redis;

    public function record(array $metrics): void
    {
        $key = 'metrics:' . date('Y-m-d:H');
        
        $this->redis->pipeline(function($pipe) use ($metrics, $key) {
            foreach ($metrics as $metric => $value) {
                $pipe->hIncrByFloat($key, $metric, $value);
            }
            $pipe->expire($key, 86400);
        });
    }

    public function getHourlyMetrics(string $date, int $hour): array
    {
        return $this->redis->hGetAll("metrics:{$date}:{$hour}");
    }
}

class LoggingService
{
    private const OPERATIONS_CHANNEL = 'operations';
    private const SECURITY_CHANNEL = 'security';
    private const PERFORMANCE_CHANNEL = 'performance';

    public function logOperation(string $operation, string $id): void
    {
        Log::channel(self::OPERATIONS_CHANNEL)->info('Operation started', [
            'operation' => $operation,
            'id' => $id,
            'timestamp' => now()
        ]);
    }

    public function logSecurity(string $event, array $context = []): void
    {
        Log::channel(self::SECURITY_CHANNEL)->warning($event, array_merge(
            $context,
            ['timestamp' => now()]
        ));
    }

    public function logPerformance(array $metrics): void
    {
        Log::channel(self::PERFORMANCE_CHANNEL)->info('Performance metrics', [
            'metrics' => $metrics,
            'timestamp' => now()
        ]);
    }
}

class QueueManager
{
    private Redis $redis;

    public function push(string $queue, $job): void
    {
        $this->redis->lpush("queue:{$queue}", serialize($job));
    }

    public function pop(string $queue)
    {
        $job = $this->redis->rpop("queue:{$queue}");
        return $job ? unserialize($job) : null;
    }

    public function size(string $queue): int
    {
        return $this->redis->llen("queue:{$queue}");
    }
}

class RateLimiter
{
    private Redis $redis;

    public function attempt(string $key, int $maxAttempts, int $decay): bool
    {
        $current = $this->redis->incr($key);
        
        if ($current === 1) {
            $this->redis->expire($key, $decay);
        }
        
        return $current <= $maxAttempts;
    }

    public function remaining(string $key, int $maxAttempts): int
    {
        $current = $this->redis->get($key) ?: 0;
        return max(0, $maxAttempts - $current);
    }
}
