namespace App\Infrastructure;

class DatabaseManager implements DatabaseInterface
{
    private ConnectionPool $pool;
    private QueryBuilder $builder;
    private Logger $logger;
    private MetricsCollector $metrics;

    public function transaction(callable $callback): mixed 
    {
        $connection = $this->pool->acquire();
        $startTime = microtime(true);
        
        try {
            $connection->beginTransaction();
            $result = $callback($connection);
            $connection->commit();
            
            $this->metrics->recordQueryTime(microtime(true) - $startTime);
            
            return $result;
            
        } catch (\Exception $e) {
            $connection->rollBack();
            $this->handleDatabaseError($e);
            throw $e;
            
        } finally {
            $this->pool->release($connection);
        }
    }

    public function query(): QueryBuilder 
    {
        return $this->builder->newQuery();
    }

    private function handleDatabaseError(\Exception $e): void 
    {
        $this->logger->error('Database error', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

class CacheManager implements CacheInterface
{
    private CacheStore $store;
    private int $ttl;
    private Logger $logger;

    public function remember(string $key, callable $callback): mixed 
    {
        try {
            if ($cached = $this->get($key)) {
                return $cached;
            }

            $value = $callback();
            $this->set($key, $value);
            return $value;
            
        } catch (\Exception $e) {
            $this->handleCacheError($e, $key);
            throw $e;
        }
    }

    public function invalidatePrefix(string $prefix): void 
    {
        try {
            $this->store->deleteByPrefix($prefix);
        } catch (\Exception $e) {
            $this->handleCacheError($e, $prefix);
            throw $e;
        }
    }

    private function handleCacheError(\Exception $e, string $context): void 
    {
        $this->logger->error('Cache operation failed', [
            'context' => $context,
            'error' => $e->getMessage()
        ]);
    }
}

class PerformanceMonitor implements MonitorInterface
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

class BackupManager implements BackupInterface
{
    private StorageManager $storage;
    private EncryptionService $encryption;
    private Logger $logger;

    public function createPoint(): string 
    {
        try {
            $data = $this->gatherSystemState();
            $encrypted = $this->encryption->encrypt(serialize($data));
            $id = uniqid('backup_', true);
            
            $this->storage->store($id, $encrypted);
            $this->logger->info('Backup point created', ['id' => $id]);
            
            return $id;
            
        } catch (\Exception $e) {
            $this->handleBackupError($e);
            throw $e;
        }
    }

    public function restore(string $id): void 
    {
        try {
            $encrypted = $this->storage->retrieve($id);
            $data = unserialize($this->encryption->decrypt($encrypted));
            
            $this->restoreSystemState($data);
            $this->logger->info('System state restored', ['id' => $id]);
            
        } catch (\Exception $e) {
            $this->handleBackupError($e);
            throw $e;
        }
    }

    private function handleBackupError(\Exception $e): void 
    {
        $this->logger->error('Backup operation failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
