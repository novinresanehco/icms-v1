namespace App\Infrastructure;

class SystemMonitor implements MonitorInterface
{
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private ConfigManager $config;

    public function monitorOperation(string $operation, callable $callback): mixed
    {
        $startTime = microtime(true);
        
        try {
            $result = $callback();
            $this->recordSuccess($operation, microtime(true) - $startTime);
            return $result;
            
        } catch (\Exception $e) {
            $this->recordFailure($operation, $e);
            throw $e;
        }
    }

    private function recordSuccess(string $operation, float $duration): void
    {
        $this->metrics->record([
            'operation' => $operation,
            'duration' => $duration,
            'status' => 'success',
            'timestamp' => time()
        ]);

        if ($duration > $this->config->get('thresholds.operation_duration')) {
            $this->alerts->warning("Operation $operation exceeded duration threshold");
        }
    }

    private function recordFailure(string $operation, \Exception $e): void
    {
        $this->metrics->record([
            'operation' => $operation,
            'error' => $e->getMessage(),
            'status' => 'failure',
            'timestamp' => time()
        ]);

        $this->alerts->critical("Operation $operation failed", [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

class DatabaseManager implements DatabaseInterface 
{
    private ConnectionPool $pool;
    private QueryBuilder $builder;
    private Logger $logger;

    public function transaction(callable $callback): mixed
    {
        $connection = $this->pool->acquire();
        
        try {
            $connection->beginTransaction();
            $result = $callback($connection);
            $connection->commit();
            return $result;
            
        } catch (\Exception $e) {
            $connection->rollBack();
            $this->logger->error('Transaction failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
            
        } finally {
            $this->pool->release($connection);
        }
    }

    public function query(): QueryBuilder
    {
        return $this->builder->newQuery();
    }
}

class CacheManager implements CacheInterface
{
    private CacheStore $store;
    private int $ttl;
    private Logger $logger;

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        try {
            if ($cached = $this->get($key)) {
                return $cached;
            }

            $value = $callback();
            $this->set($key, $value, $ttl ?? $this->ttl);
            return $value;
            
        } catch (\Exception $e) {
            $this->logger->error('Cache operation failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function invalidatePrefix(string $prefix): void
    {
        try {
            $this->store->deleteByPrefix($prefix);
        } catch (\Exception $e) {
            $this->logger->error('Cache invalidation failed', [
                'prefix' => $prefix,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}

class PerformanceMonitor implements PerformanceInterface
{
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private ConfigManager $config;

    public function checkSystem(): void
    {
        $metrics = [
            'cpu' => $this->getCpuUsage(),
            'memory' => $this->getMemoryUsage(),
            'disk' => $this->getDiskUsage()
        ];

        foreach ($metrics as $metric => $value) {
            $this->recordMetric($metric, $value);
        }
    }

    private function recordMetric(string $name, float $value): void
    {
        $this->metrics->record($name, $value);

        $threshold = $this->config->get("thresholds.$name");
        if ($threshold && $value > $threshold) {
            $this->alerts->warning("High $name usage: $value%");
        }
    }

    private function getCpuUsage(): float
    {
        return sys_getloadavg()[0] * 100;
    }

    private function getMemoryUsage(): float
    {
        return memory_get_usage(true) / memory_get_peak_usage(true) * 100;
    }
}
