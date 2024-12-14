<?php

namespace App\Core\Protection;

use App\Core\Security\SecurityManager;
use App\Core\Monitor\SystemMonitor;
use Illuminate\Support\Facades\{DB, Log};

final class CriticalSystemManager
{
    private SecurityManager $security;
    private SystemMonitor $monitor;
    private StateValidator $validator;
    private BackupManager $backup;

    public function executeProtected(callable $operation, array $context): mixed
    {
        $operationId = $this->monitor->startOperation();
        $checkpoint = $this->backup->createCheckpoint();
        
        try {
            $this->validateSystemState();
            $result = $this->executeTransaction($operation, $context);
            $this->validateResult($result);
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->handleSystemFailure($e, $checkpoint);
            throw new SystemFailureException($e->getMessage(), 0, $e);
        }
    }

    private function executeTransaction(callable $operation, array $context): mixed
    {
        return DB::transaction(function() use ($operation, $context) {
            $this->security->validateContext($context);
            return $operation();
        });
    }

    private function validateSystemState(): void
    {
        if (!$this->validator->checkSystemHealth()) {
            throw new SystemStateException('System health check failed');
        }
    }

    private function handleSystemFailure(\Throwable $e, string $checkpoint): void
    {
        Log::critical('System failure', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->backup->restore($checkpoint);
        $this->monitor->recordFailure($e);
    }
}

final class StateValidator
{
    private array $healthChecks = [
        'memory' => 0.85, // 85% threshold
        'cpu' => 0.80,    // 80% threshold
        'disk' => 0.90,   // 90% threshold
    ];

    public function checkSystemHealth(): bool
    {
        foreach ($this->healthChecks as $check => $threshold) {
            if (!$this->validateResource($check, $threshold)) {
                return false;
            }
        }
        return true;
    }

    private function validateResource(string $resource, float $threshold): bool
    {
        $usage = match($resource) {
            'memory' => memory_get_usage(true) / ini_get('memory_limit'),
            'cpu' => sys_getloadavg()[0] / 100,
            'disk' => 1 - (disk_free_space('/') / disk_total_space('/')),
            default => throw new ValidationException("Unknown resource: $resource")
        };

        return $usage <= $threshold;
    }
}

final class BackupManager
{
    private array $backups = [];
    private string $backupPath;

    public function createCheckpoint(): string
    {
        $checkpointId = uniqid('backup_', true);
        $state = $this->captureState();
        
        $this->backups[$checkpointId] = [
            'timestamp' => microtime(true),
            'state' => $state
        ];

        $this->saveBackup($checkpointId, $state);
        return $checkpointId;
    }

    public function restore(string $checkpointId): void
    {
        if (!isset($this->backups[$checkpointId])) {
            throw new BackupException("Checkpoint not found: $checkpointId");
        }

        $backup = $this->loadBackup($checkpointId);
        $this->restoreState($backup['state']);
    }

    private function captureState(): array
    {
        return [
            'db' => $this->captureDatabaseState(),
            'files' => $this->captureFileState(),
            'cache' => $this->captureCacheState()
        ];
    }

    private function captureDatabaseState(): array
    {
        return DB::select('SHOW MASTER STATUS')[0] ?? [];
    }

    private function saveBackup(string $id, array $state): void
    {
        file_put_contents(
            $this->backupPath . '/' . $id . '.backup',
            serialize($state)
        );
    }
}

final class SystemMonitor
{
    private MetricsCollector $metrics;
    private AlertSystem $alerts;

    public function startOperation(): string
    {
        $operationId = uniqid('op_', true);
        
        $this->metrics->record($operationId, [
            'start_time' => microtime(true),
            'memory_start' => memory_get_usage(true),
            'cpu_start' => sys_getloadavg()[0]
        ]);

        return $operationId;
    }

    public function recordFailure(\Throwable $e): void
    {
        $this->alerts->trigger('SYSTEM_FAILURE', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => microtime(true)
        ]);
    }
}

class SystemFailureException extends \Exception {}
class SystemStateException extends \Exception {}
class ValidationException extends \Exception {}
class BackupException extends \Exception {}
