<?php

namespace App\Core\Infrastructure;

use Illuminate\Support\Facades\{Cache, Redis, Log};
use Illuminate\Contracts\Cache\LockProvider;

class CriticalCacheManager
{
    private string $prefix = 'cms:';
    private int $defaultTtl = 3600;
    private LockProvider $locks;

    public function get(string $key): mixed
    {
        return Cache::tags(['cms'])->get($this->prefix . $key);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        Cache::tags(['cms'])->put(
            $this->prefix . $key,
            $value,
            $ttl ?? $this->defaultTtl
        );
    }

    public function remember(string $key, callable $callback): mixed
    {
        $lock = $this->locks->lock($this->prefix . $key . ':lock', 10);

        try {
            return Cache::tags(['cms'])->remember(
                $this->prefix . $key,
                $this->defaultTtl,
                function() use ($callback, $key) {
                    $value = $callback();
                    Log::debug("Cache generated for key: $key");
                    return $value;
                }
            );
        } finally {
            $lock->release();
        }
    }

    public function invalidate(string $key): void
    {
        Cache::tags(['cms'])->forget($this->prefix . $key);
    }
}

class QueueManager
{
    private Redis $redis;
    private string $prefix = 'queue:';
    private int $defaultDelay = 0;

    public function push(string $queue, array $job, int $delay = 0): void
    {
        $payload = $this->createPayload($job);
        
        if ($delay > 0) {
            $this->redis->zadd(
                $this->prefix . 'delayed:' . $queue,
                time() + $delay,
                $payload
            );
        } else {
            $this->redis->rpush(
                $this->prefix . $queue,
                $payload
            );
        }
    }

    public function pop(string $queue): ?array
    {
        $payload = $this->redis->lpop($this->prefix . $queue);
        
        if (!$payload) {
            return null;
        }

        return json_decode($payload, true);
    }

    private function createPayload(array $job): string
    {
        return json_encode([
            'id' => uniqid('job:', true),
            'data' => $job,
            'created_at' => time()
        ]);
    }
}

class HealthMonitor
{
    private array $checks = [];
    private ErrorHandler $errors;

    public function check(): array
    {
        $results = [];

        foreach ($this->checks as $name => $check) {
            try {
                $results[$name] = $check();
            } catch (\Exception $e) {
                $this->errors->handle($e);
                $results[$name] = false;
            }
        }

        return $results;
    }

    public function addCheck(string $name, callable $check): void
    {
        $this->checks[$name] = $check;
    }

    public function getStatus(): bool
    {
        return !in_array(false, $this->check());
    }
}

class ErrorHandler
{
    private LogManager $logger;
    private AlertManager $alerts;

    public function handle(\Throwable $e): void
    {
        $this->logger->error('System error', [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);

        if ($this->isCritical($e)) {
            $this->alerts->critical([
                'type' => 'system_error',
                'message' => $e->getMessage(),
                'time' => time()
            ]);
        }
    }

    private function isCritical(\Throwable $e): bool
    {
        return $e instanceof CriticalException ||
               $e instanceof SecurityException ||
               $e instanceof DatabaseException;
    }
}

class PerformanceMonitor
{
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private array $thresholds;

    public function measure(string $key, callable $operation): mixed
    {
        $start = microtime(true);
        
        try {
            return $operation();
        } finally {
            $duration = microtime(true) - $start;
            $this->record($key, $duration);
        }
    }

    private function record(string $key, float $duration): void
    {
        $this->metrics->record($key, $duration);

        if (isset($this->thresholds[$key]) && $duration > $this->thresholds[$key]) {
            $this->alerts->warning([
                'type' => 'performance_threshold',
                'key' => $key,
                'duration' => $duration,
                'threshold' => $this->thresholds[$key]
            ]);
        }
    }
}

class SecurityMonitor
{
    private IpBlacklist $blacklist;
    private RateLimit $rateLimit;
    private LogManager $logger;

    public function validateRequest(Request $request): bool
    {
        if ($this->blacklist->contains($request->ip())) {
            $this->logger->warning('Blocked IP attempt', [
                'ip' => $request->ip()
            ]);
            return false;
        }

        if (!$this->rateLimit->check($request)) {
            $this->logger->warning('Rate limit exceeded', [
                'ip' => $request->ip(),
                'endpoint' => $request->path()
            ]);
            return false;
        }

        return true;
    }
}
