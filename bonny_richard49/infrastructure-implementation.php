namespace App\Infrastructure;

class PerformanceMonitor
{
    private MetricsCollector $metrics;
    private AlertManager $alerts;

    public function trackOperation(string $operation, callable $callback)
    {
        $start = microtime(true);

        try {
            $result = $callback();
            $this->recordSuccess($operation, microtime(true) - $start);
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
    }

    private function recordFailure(string $operation, \Exception $e): void
    {
        $this->metrics->record([
            'operation' => $operation,
            'error' => $e->getMessage(),
            'status' => 'failure',
            'timestamp' => time()
        ]);

        $this->alerts->critical("Operation $operation failed: " . $e->getMessage());
    }
}

class DatabaseManager
{
    private ConnectionPool $pool;
    private QueryBuilder $builder;
    private Logger $logger;

    public function transaction(callable $callback)
    {
        $connection = $this->pool->acquire();
        
        try {
            $connection->beginTransaction();
            $result = $callback($connection);
            $connection->commit();
            return $result;
        } catch (\Exception $e) {
            $connection->rollBack();
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

class CacheStore
{
    private $handler;
    private $config;

    public function get(string $key)
    {
        try {
            return $this->handler->get($key);
        } catch (\Exception $e) {
            $this->handleError($e);
            return null;
        }
    }

    public function put(string $key, $value, int $ttl): void
    {
        try {
            $this->handler->set($key, $value, $ttl);
        } catch (\Exception $e) {
            $this->handleError($e);
        }
    }

    private function handleError(\Exception $e): void
    {
        // Critical error handling
        throw new CacheException($e->getMessage(), $e->getCode(), $e);
    }
}

class BackupManager
{
    private StorageManager $storage;
    private EncryptionService $encryption;

    public function backup(): void
    {
        $data = $this->gatherBackupData();
        $encrypted = $this->encryption->encrypt(serialize($data));
        $this->storage->store($encrypted);
    }

    private function gatherBackupData(): array
    {
        return [
            'timestamp' => time(),
            'database' => $this->getDatabaseDump(),
            'files' => $this->getFilesList(),
            'config' => $this->getConfigSnapshot()
        ];
    }
}

class LogManager
{
    private array $handlers;
    private LogFormatter $formatter;

    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    private function log(string $level, string $message, array $context): void
    {
        $entry = $this->formatter->format([
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'timestamp' => time()
        ]);

        foreach ($this->handlers as $handler) {
            $handler->handle($entry);
        }
    }
}

class SystemMonitor
{
    private PerformanceCollector $collector;
    private AlertManager $alerts;
    private MetricsStore $metrics;

    public function checkSystem(): void
    {
        $metrics = $this->collector->collect();
        
        if ($this->hasIssues($metrics)) {
            $this->alerts->warning('System issues detected', $metrics);
        }

        $this->metrics->store($metrics);
    }

    private function hasIssues(array $metrics): bool
    {
        return $metrics['cpu_usage'] > 70 
            || $metrics['memory_usage'] > 80
            || $metrics['disk_usage'] > 85;
    }
}
