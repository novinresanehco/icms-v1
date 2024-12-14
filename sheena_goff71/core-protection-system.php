<?php

namespace App\Core\Protection;

use Illuminate\Support\Facades\{DB, Log, Cache};
use App\Core\Exceptions\{
    ValidationException,
    SecurityException,
    SystemException
};
use App\Core\Interfaces\{
    ValidationInterface,
    SecurityInterface,
    AuditInterface
};

class CoreProtectionSystem implements SecurityInterface
{
    private ValidationService $validator;
    private AuditService $audit;
    private BackupService $backup;
    private MonitoringService $monitor;
    private array $config;

    public function __construct(
        ValidationService $validator,
        AuditService $audit,
        BackupService $backup,
        MonitoringService $monitor,
        array $config
    ) {
        $this->validator = $validator;
        $this->audit = $audit;
        $this->backup = $backup;
        $this->monitor = $monitor;
        $this->config = $config;
    }

    public function executeProtectedOperation(callable $operation, array $context): mixed
    {
        $operationId = $this->generateOperationId();
        $backupId = $this->backup->createBackupPoint();
        
        $this->monitor->startOperation($operationId);
        $this->validateOperationContext($context);
        
        DB::beginTransaction();
        
        try {
            $result = $this->executeWithProtection($operation, $operationId);
            
            $this->validateResult($result);
            $this->verifySystemState();
            
            DB::commit();
            $this->audit->logSuccess($operationId);
            
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            
            $this->handleSystemFailure($e, $operationId, $backupId);
            throw new SystemException('Protected operation failed', 0, $e);
            
        } finally {
            $this->monitor->endOperation($operationId);
            $this->cleanup($backupId);
        }
    }

    protected function executeWithProtection(callable $operation, string $operationId): mixed
    {
        return $this->monitor->track($operationId, function() use ($operation) {
            return $operation();
        });
    }

    protected function validateOperationContext(array $context): void
    {
        if (!$this->validator->validateContext($context)) {
            throw new ValidationException('Invalid operation context');
        }

        if (!$this->validator->checkSecurityConstraints($context)) {
            throw new SecurityException('Security constraints not met');
        }

        if (!$this->validator->verifySystemState()) {
            throw new SystemException('Invalid system state');
        }
    }

    protected function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Operation result validation failed');
        }
    }

    protected function verifySystemState(): void
    {
        $metrics = $this->monitor->getSystemMetrics();
        
        if ($metrics['memory_usage'] > $this->config['memory_limit']) {
            throw new SystemException('Memory limit exceeded');
        }
        
        if ($metrics['cpu_usage'] > $this->config['cpu_limit']) {
            throw new SystemException('CPU limit exceeded');
        }
        
        if (!$this->monitor->verifySystemHealth()) {
            throw new SystemException('System health check failed');
        }
    }

    protected function handleSystemFailure(\Throwable $e, string $operationId, string $backupId): void
    {
        $this->audit->logFailure($operationId, [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'metrics' => $this->monitor->getSystemMetrics()
        ]);

        if ($this->isRecoverable($e)) {
            $this->executeRecovery($backupId);
        }

        if ($this->isCriticalFailure($e)) {
            $this->executeCriticalFailureProtocol($e, $operationId);
        }
    }

    protected function executeRecovery(string $backupId): void
    {
        try {
            $this->backup->restoreFromPoint($backupId);
        } catch (\Exception $e) {
            Log::critical('Recovery failed', [
                'exception' => $e->getMessage(),
                'backup_id' => $backupId
            ]);
        }
    }

    protected function executeCriticalFailureProtocol(\Throwable $e, string $operationId): void
    {
        Log::critical('Critical system failure', [
            'operation_id' => $operationId,
            'exception' => $e->getMessage(),
            'system_state' => $this->monitor->captureSystemState()
        ]);

        Cache::tags(['critical', 'security'])->put(
            "failure:$operationId",
            $this->monitor->captureSystemState(),
            now()->addHours(24)
        );

        if ($this->config['emergency_shutdown_enabled']) {
            $this->initiateEmergencyShutdown();
        }
    }

    protected function initiateEmergencyShutdown(): void
    {
        $this->monitor->stopAllOperations();
        $this->backup->createEmergencyBackup();
        Cache::tags(['system', 'critical'])->flush();
    }

    protected function cleanup(string $backupId): void
    {
        try {
            $this->backup->cleanupBackupPoint($backupId);
        } catch (\Exception $e) {
            Log::error('Backup cleanup failed', [
                'backup_id' => $backupId,
                'exception' => $e->getMessage()
            ]);
        }
    }

    protected function generateOperationId(): string
    {
        return uniqid('op_', true);
    }

    protected function isRecoverable(\Throwable $e): bool
    {
        return !in_array($e::class, $this->config['non_recoverable_exceptions'] ?? []);
    }

    protected function isCriticalFailure(\Throwable $e): bool
    {
        return in_array($e::class, $this->config['critical_exceptions'] ?? []);
    }
}
