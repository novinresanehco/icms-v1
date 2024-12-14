<?php
namespace App\Infrastructure;

class DatabaseManager 
{
    private ConnectionPool $pool;
    private QueryBuilder $builder;
    private LoggerInterface $logger;
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

class PerformanceMonitor
{
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private ConfigManager $config;

    public function recordMetric(string $name, float $value, array $tags = []): void
    {
        $this->metrics->record($name, $value, $tags);

        $threshold = $this->config->get("metrics.{$name}.threshold");
        if ($threshold && $value > $threshold) {
            $this->alerts->trigger(
                "High {$name}",
                "Value {$value} exceeds threshold {$threshold}",
                AlertLevel::Warning
            );
        }
    }

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

    private function getCpuUsage(): float
    {
        return sys_getloadavg()[0] * 100;
    }

    private function getMemoryUsage(): float
    {
        return memory_get_usage(true) / memory_get_peak_usage(true) * 100;
    }
}

class BackupManager
{
    private StorageManager $storage;
    private EncryptionService $encryption;
    private LoggerInterface $logger;

    public function createBackup(string $identifier): BackupResult
    {
        $data = $this->collectBackupData();
        $encrypted = $this->encryption->encrypt(serialize($data));
        
        try {
            $path = $this->storage->store($identifier, $encrypted);
            $this->logger->info('Backup created', ['identifier' => $identifier]);
            return new BackupResult($path);
            
        } catch (\Exception $e) {
            $this->logger->error('Backup failed', [
                'identifier' => $identifier,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function restore(string $identifier): RestoreResult
    {
        try {
            $encrypted = $this->storage->retrieve($identifier);
            $data = unserialize($this->encryption->decrypt($encrypted));
            
            $this->performRestore($data);
            $this->logger->info('Backup restored', ['identifier' => $identifier]);
            
            return new RestoreResult(true);
            
        } catch (\Exception $e) {
            $this->logger->error('Restore failed', [
                'identifier' => $identifier,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function collectBackupData(): array
    {
        return [
            'timestamp' => time(),
            'database' => $this->getDatabaseDump(),
            'files' => $this->getFilesList(),
            'config' => $this->getConfigSnapshot()
        ];
    }
}

class SystemMonitor
{
    private PerformanceCollector $collector;
    private AlertManager $alerts;
    private MetricsStore $metrics;
    private ConfigManager $config;

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
        $thresholds = $this->config->get('monitoring.thresholds');
        
        return $metrics['cpu_usage'] > $thresholds['cpu']
            || $metrics['memory_usage'] > $thresholds['memory']
            || $metrics['disk_usage'] > $thresholds['disk'];
    }
}
