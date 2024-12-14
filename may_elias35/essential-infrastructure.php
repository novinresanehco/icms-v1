<?php

namespace App\Core\Infrastructure;

use Illuminate\Support\Facades\{Cache, Log, DB};
use Illuminate\Support\Collection;

class SystemMonitor
{
    private MetricsCollector $metrics;
    private SecurityMonitor $security;
    private PerformanceAnalyzer $performance;
    
    public function track(string $operation, callable $callback): mixed
    {
        $startTime = microtime(true);
        $memoryStart = memory_get_usage();
        
        try {
            $result = $callback();
            
            $this->recordSuccess(
                $operation,
                microtime(true) - $startTime,
                memory_get_usage() - $memoryStart
            );
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->recordFailure($operation, $e);
            throw $e;
        }
    }

    public function checkSystemHealth(): SystemHealth
    {
        return new SystemHealth([
            'cpu' => sys_getloadavg()[0],
            'memory' => memory_get_usage(true),
            'disk' => disk_free_space('/'),
            'database' => $this->checkDatabaseHealth(),
            'cache' => $this->checkCacheHealth()
        ]);
    }

    protected function recordSuccess(string $operation, float $duration, int $memory): void
    {
        $this->metrics->record([
            'operation' => $operation,
            'duration' => $duration,
            'memory' => $memory,
            'status' => 'success'
        ]);
    }

    protected function recordFailure(string $operation, \Throwable $e): void
    {
        $this->metrics->record([
            'operation' => $operation,
            'error' => $e->getMessage(),
            'status' => 'failure'
        ]);
    }
}

class CacheSystem
{
    private const DEFAULT_TTL = 3600;
    private array $tags = [];

    public function remember(string $key, mixed $value, ?int $ttl = null): mixed
    {
        return Cache::tags($this->tags)->remember(
            $this->generateKey($key),
            $ttl ?? self::DEFAULT_TTL,
            fn() => $value
        );
    }

    public function invalidate(string $key): void
    {
        Cache::tags($this->tags)->forget($this->generateKey($key));
    }

    public function tags(array $tags): self
    {
        $this->tags = array_merge($this->tags, $tags);
        return $this;
    }

    protected function generateKey(string $key): string
    {
        return hash('sha256', $key . config('app.key'));
    }
}

class HealthMonitor
{
    private Collection $services;
    private LogManager $logger;

    public function __construct(LogManager $logger)
    {
        $this->services = new Collection();
        $this->logger = $logger;
    }

    public function register(string $name, callable $check): void
    {
        $this->services->put($name, $check);
    }

    public function check(): Collection
    {
        return $this->services->map(function($check, $name) {
            try {
                return $check();
            } catch (\Throwable $e) {
                $this->logger->error("Health check failed for {$name}", [
                    'error' => $e->getMessage(),
                    'service' => $name
                ]);
                return false;
            }
        });
    }
}

class SecurityMonitor
{
    private const ALERT_THRESHOLD = 5;
    private array $failedAttempts = [];
    
    public function trackAccess(string $ip, string $resource): void
    {
        $key = "{$ip}:{$resource}";
        
        if (!isset($this->failedAttempts[$key])) {
            $this->failedAttempts[$key] = [
                'count' => 0,
                'first_attempt' => time()
            ];
        }
        
        $this->failedAttempts[$key]['count']++;
        
        if ($this->detectThreat($key)) {
            $this->handleThreat($ip, $resource);
        }
    }

    public function validateRate(string $ip, string $resource): bool
    {
        $key = "{$ip}:{$resource}";
        return !isset($this->failedAttempts[$key]) || 
               $this->failedAttempts[$key]['count'] < self::ALERT_THRESHOLD;
    }

    protected function detectThreat(string $key): bool
    {
        $attempts = $this->failedAttempts[$key];
        return $attempts['count'] >= self::ALERT_THRESHOLD &&
               (time() - $attempts['first_attempt']) < 300;
    }

    protected function handleThreat(string $ip, string $resource): void
    {
        Log::alert('Potential security threat detected', [
            'ip' => $ip,
            'resource' => $resource,
            'attempts' => $this->failedAttempts["{$ip}:{$resource}"]
        ]);
        
        throw new SecurityException('Access denied due to suspicious activity');
    }
}

class PerformanceAnalyzer
{
    private const THRESHOLDS = [
        'response_time' => 200,
        'memory_usage' => 83886080,
        'query_time' => 50
    ];

    public function analyze(array $metrics): PerformanceReport
    {
        $issues = collect($metrics)->filter(function($value, $key) {
            return isset(self::THRESHOLDS[$key]) && $value > self::THRESHOLDS[$key];
        });

        if ($issues->isNotEmpty()) {
            Log::warning('Performance thresholds exceeded', $issues->toArray());
        }

        return new PerformanceReport($metrics, $issues->toArray());
    }
}
