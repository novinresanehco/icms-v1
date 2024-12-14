<?php

namespace App\Core\Infrastructure;

class PerformanceManager implements PerformanceManagerInterface
{
    private CacheManager $cache;
    private MonitoringService $monitor;
    private MetricsCollector $metrics;
    private ResourceManager $resources;

    public function __construct(
        CacheManager $cache,
        MonitoringService $monitor,
        MetricsCollector $metrics,
        ResourceManager $resources
    ) {
        $this->cache = $cache;
        $this->monitor = $monitor;
        $this->metrics = $metrics;
        $this->resources = $resources;
    }

    public function trackOperation(string $operation, callable $callback)
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        try {
            $result = $callback();

            $this->recordMetrics(
                $operation,
                microtime(true) - $startTime,
                memory_get_usage() - $startMemory
            );

            return $result;
        } catch (\Throwable $e) {
            $this->handleFailure($operation, $e);
            throw $e;
        }
    }

    private function recordMetrics(string $operation, float $duration, int $memory): void
    {
        $this->metrics->record([
            'operation' => $operation,
            'duration' => $duration,
            'memory' => $memory,
            'cpu' => sys_getloadavg()[0],
            'timestamp' => time()
        ]);

        if ($duration > $this->config['threshold']) {
            $this->monitor->reportSlowOperation($operation, $duration);
        }
    }

    private function handleFailure(string $operation, \Throwable $e): void
    {
        $this->monitor->reportFailure($operation, $e);
        $this->resources->checkHealth();
    }
}

class CacheManager implements CacheManagerInterface
{
    private array $stores = [];
    private MetricsCollector $metrics;
    private array $config;

    public function __construct(array $config, MetricsCollector $metrics)
    {
        $this->config = $config;
        $this->metrics = $metrics;
    }

    public function remember(string $key, int $ttl, callable $callback)
    {
        $startTime = microtime(true);

        try {
            if ($cached = $this->get($key)) {
                $this->recordHit($key, microtime(true) - $startTime);
                return $cached;
            }

            $value = $callback();
            $this->put($key, $value, $ttl);
            $this->recordMiss($key, microtime(true) - $startTime);

            return $value;
        } catch (\Exception $e) {
            $this->handleFailure($key, $e);
            throw $e;
        }
    }

    private function recordHit(string $key, float $duration): void
    {
        $this->metrics->increment('cache.hits');
        $this->metrics->timing('cache.hit_time', $duration);
    }

    private function recordMiss(string $key, float $duration): void
    {
        $this->metrics->increment('cache.misses');
        $this->metrics->timing('cache.miss_time', $duration);
    }

    private function handleFailure(string $key, \Exception $e): void
    {
        $this->metrics->increment('cache.failures');
        Log::error("Cache operation failed for key: $key", [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

class DatabaseManager implements DatabaseManagerInterface
{
    private ConnectionPool $pool;
    private QueryOptimizer $optimizer;
    private MonitoringService $monitor;
    private MetricsCollector $metrics;

    public function __construct(
        ConnectionPool $pool,
        QueryOptimizer $optimizer,
        MonitoringService $monitor,
        MetricsCollector $metrics
    ) {
        $this->pool = $pool;
        $this->optimizer = $optimizer;
        $this->monitor = $monitor;
        $this->metrics = $metrics;
    }

    public function executeQuery(string $query, array $params = []): Result
    {
        $startTime = microtime(true);

        try {
            $optimizedQuery = $this->optimizer->optimize($query);
            $connection = $this->pool->getConnection();
            
            $result = $connection->execute($optimizedQuery, $params);
            
            $this->recordMetrics($query, microtime(true) - $startTime);
            
            return $result;
        } catch (\Exception $e) {
            $this->handleFailure($query, $e);
            throw $e;
        }
    }

    private function recordMetrics(string $query, float $duration): void
    {
        $this->metrics->timing('db.query_time', $duration);
        
        if ($duration > $this->config['slow_query_threshold']) {
            $this->monitor->reportSlowQuery($query, $duration);
        }
    }

    private function handleFailure(string $query, \Exception $e): void
    {
        $this->metrics->increment('db.failures');
        $this->monitor->reportQueryFailure($query, $e);
    }
}

class ResourceManager implements ResourceManagerInterface
{
    private SystemMonitor $monitor;
    private AlertSystem $alerts;
    private MetricsCollector $metrics;

    public function __construct(
        SystemMonitor $monitor,
        AlertSystem $alerts,
        MetricsCollector $metrics
    ) {
        $this->monitor = $monitor;
        $this->alerts = $alerts;
        $this->metrics = $metrics;
    }

    public function checkHealth(): HealthStatus
    {
        $cpu = sys_getloadavg()[0];
        $memory = memory_get_usage(true);
        $disk = disk_free_space('/');

        $status = new HealthStatus([
            'cpu' => $cpu,
            'memory' => $memory,
            'disk' => $disk,
            'timestamp' => time()
        ]);

        $this->recordMetrics($status);
        $this->checkThresholds($status);

        return $status;
    }

    private function recordMetrics(HealthStatus $status): void
    {
        $this->metrics->gauge('system.cpu', $status->cpu);
        $this->metrics->gauge('system.memory', $status->memory);
        $this->metrics->gauge('system.disk', $status->disk);
    }

    private function checkThresholds(HealthStatus $status): void
    {
        if ($status->cpu > $this->config['cpu_threshold']) {
            $this->alerts->criticalLoad('CPU usage exceeded threshold');
        }

        if ($status->memory > $this->config['memory_threshold']) {
            $this->alerts->criticalLoad('Memory usage exceeded threshold');
        }

        if ($status->disk < $this->config['disk_threshold']) {
            $this->alerts->criticalLoad('Disk space below threshold');
        }
    }
}

interface PerformanceManagerInterface
{
    public function trackOperation(string $operation, callable $callback);
}

interface CacheManagerInterface
{
    public function remember(string $key, int $ttl, callable $callback);
}

interface DatabaseManagerInterface
{
    public function executeQuery(string $query, array $params = []): Result;
}

interface ResourceManagerInterface
{
    public function checkHealth(): HealthStatus;
}
