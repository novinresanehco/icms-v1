<?php

namespace App\Core\Performance;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use App\Core\Security\SecurityManagerInterface;
use App\Core\Monitoring\PerformanceMonitor;
use App\Core\Exceptions\PerformanceException;

class PerformanceManager implements PerformanceManagerInterface
{
    protected SecurityManagerInterface $security;
    protected PerformanceMonitor $monitor;
    protected array $config;
    protected array $metrics = [];

    public function __construct(
        SecurityManagerInterface $security,
        PerformanceMonitor $monitor,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->config = $config;
    }

    public function executeOptimizedOperation(string $key, callable $operation, array $options = []): mixed
    {
        $startTime = microtime(true);
        $operationId = uniqid('perf_', true);

        try {
            // Check cache first
            if ($this->shouldCache($key, $options)) {
                if ($cached = $this->getFromCache($key, $options)) {
                    $this->recordHit($key, $operationId);
                    return $cached;
                }
            }

            // Monitor resource usage
            $this->startResourceMonitoring($operationId);

            // Execute operation
            $result = $this->executeWithTimeout(
                $operation,
                $options['timeout'] ?? $this->config['default_timeout']
            );

            // Validate performance
            $this->validatePerformance($operationId, $startTime);

            // Cache if needed
            if ($this->shouldCache($key, $options)) {
                $this->storeInCache($key, $result, $options);
            }

            return $result;

        } catch (\Throwable $e) {
            $this->handlePerformanceFailure($e, $key, $operationId);
            throw $e;

        } finally {
            $duration = microtime(true) - $startTime;
            $this->recordMetrics($operationId, $key, $duration);
            $this->stopResourceMonitoring($operationId);
        }
    }

    protected function executeWithTimeout(callable $operation, int $timeout): mixed
    {
        $result = null;
        $completed = false;

        // Set timeout handler
        $handler = set_error_handler(function($severity, $message) use ($timeout) {
            throw new PerformanceException("Operation timed out after {$timeout}ms");
        });

        try {
            // Set execution time limit
            set_time_limit($timeout / 1000);
            
            // Execute operation
            $result = $operation();
            $completed = true;

            return $result;

        } finally {
            // Restore handler
            set_error_handler($handler);

            if (!$completed) {
                throw new PerformanceException("Operation execution failed");
            }
        }
    }

    protected function startResourceMonitoring(string $operationId): void
    {
        $this->metrics[$operationId] = [
            'memory_start' => memory_get_usage(true),
            'cpu_start' => sys_getloadavg()[0],
            'time_start' => microtime(true)
        ];

        $this->monitor->startOperation($operationId, [
            'memory_limit' => $this->config['memory_limit'],
            'cpu_limit' => $this->config['cpu_limit']
        ]);
    }

    protected function stopResourceMonitoring(string $operationId): void
    {
        if (isset($this->metrics[$operationId])) {
            $metrics = $this->metrics[$operationId];
            $metrics['memory_peak'] = memory_get_peak_usage(true);
            $metrics['cpu_end'] = sys_getloadavg()[0];
            $metrics['duration'] = microtime(true) - $metrics['time_start'];

            $this->monitor->recordMetrics($operationId, $metrics);
            $this->monitor->stopOperation($operationId);

            unset($this->metrics[$operationId]);
        }
    }

    protected function validatePerformance(string $operationId, float $startTime): void
    {
        $metrics = $this->metrics[$operationId] ?? [];
        $duration = microtime(true) - $startTime;

        if ($duration > $this->config['max_execution_time']) {
            throw new PerformanceException(
                "Operation exceeded maximum execution time"
            );
        }

        if (isset($metrics['memory_peak']) && 
            $metrics['memory_peak'] > $this->config['memory_limit']) {
            throw new PerformanceException(
                "Operation exceeded memory limit"
            );
        }

        if (isset($metrics['cpu_end']) && 
            $metrics['cpu_end'] > $this->config['cpu_limit']) {
            throw new PerformanceException(
                "Operation exceeded CPU limit"
            );
        }
    }

    protected function shouldCache(string $key, array $options): bool
    {
        return !isset($options['cache']) || $options['cache'] !== false;
    }

    protected function getFromCache(string $key, array $options): mixed
    {
        $tags = $options['tags'] ?? [];
        $tags[] = 'performance';

        return Cache::tags($tags)->get($key);
    }

    protected function storeInCache(string $key, mixed $value, array $options): void
    {
        $tags = $options['tags'] ?? [];
        $tags[] = 'performance';

        $ttl = $options['ttl'] ?? $this->config['default_cache_ttl'];

        Cache::tags($tags)->put($key, $value, $ttl);
    }

    protected function recordHit(string $key, string $operationId): void
    {
        $this->monitor->recordCacheHit($key, $operationId);

        Redis::hincrby('cache_hits', $key, 1);
    }

    protected function recordMetrics(string $operationId, string $key, float $duration): void
    {
        $metrics = [
            'duration' => $duration,
            'memory' => memory_get_peak_usage(true),
            'cpu' => sys_getloadavg()[0]
        ];

        $this->monitor->recordOperationMetrics($operationId, $key, $metrics);

        // Store for analysis
        Redis::zadd('operation_durations', $duration, "$key:$operationId");
    }

    protected function handlePerformanceFailure(
        \Throwable $e,
        string $key,
        string $operationId
    ): void {
        // Log failure
        Log::error('Performance failure', [
            'operation_id' => $operationId,
            'key' => $key,
            'metrics' => $this->metrics[$operationId] ?? [],
            'error' => $e->getMessage()
        ]);

        // Record failure metrics
        $this->monitor->recordFailure($operationId, $key, $e);

        // Execute performance recovery if needed
        $this->executePerformanceRecovery($key, $operationId, $e);

        // Clear metrics
        unset($this->metrics[$operationId]);
    }

    protected function executePerformanceRecovery(
        string $key,
        string $operationId,
        \Throwable $e
    ): void {
        // Implement specific recovery logic
        if ($e instanceof PerformanceException) {
            // Clear related caches
            $this->clearRelatedCaches($key);
            
            // Adjust resource limits if needed
            $this->adjustResourceLimits($key, $operationId);
        }
    }

    protected function clearRelatedCaches(string $key): void
    {
        Cache::tags(['performance', $key])->flush();
    }

    protected function adjustResourceLimits(string $key, string $operationId): void
    {
        $metrics = $this->metrics[$operationId] ?? [];
        
        if (!empty($metrics)) {
            $this->monitor->suggestResourceAdjustments($key, $metrics);
        }
    }
}
