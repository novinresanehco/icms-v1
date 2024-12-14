<?php
namespace App\Core\Infrastructure;

class SystemMonitor implements MonitoringInterface
{
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private SecurityCore $security;

    public function monitor(): SystemStatus
    {
        // Collect metrics
        $metrics = $this->metrics->collect();
        
        if ($metrics->hasCritical()) {
            $this->handleCritical($metrics);
        }

        return new SystemStatus($metrics);
    }

    private function handleCritical(Metrics $metrics): void
    {
        $this->alerts->sendCritical($metrics);
        $this->security->logSecurityEvent('critical_system_issue', $metrics);
    }
}

class CacheManager
{
    private CacheStore $store;
    private SecurityCore $security;

    public function remember(string $key, callable $callback): mixed
    {
        if ($cached = $this->get($key)) {
            return $cached;
        }

        $value = $callback();
        $this->set($key, $value);
        return $value;
    }
}

class BackupManager 
{
    private SecurityCore $security;
    private StorageManager $storage;
    private ValidationService $validator;

    public function createBackup(): BackupResult
    {
        return $this->security->validateCriticalOperation(
            new CreateBackupOperation($this->storage, $this->validator)
        );
    }
}
