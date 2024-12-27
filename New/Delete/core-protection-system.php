<?php

namespace App\Core\Protection;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Core\Exceptions\SystemFailureException;
use App\Core\Interfaces\{
    SecurityInterface,
    ValidationInterface,
    AuditInterface,
    MonitoringInterface
};

class CoreProtectionSystem implements SecurityInterface
{
    protected ValidationService $validator;
    protected AuditService $auditor;
    protected MonitoringService $monitor;
    protected BackupService $backup;
    
    public function __construct(
        ValidationService $validator,
        AuditService $auditor,
        MonitoringService $monitor,
        BackupService $backup
    ) {
        $this->validator = $validator;
        $this->auditor = $auditor;
        $this->monitor = $monitor;
        $this->backup = $backup;
    }

    /**
     * Executes critical operation with comprehensive protection
     * @throws SystemFailureException
     */
    public function executeProtectedOperation(callable $operation, array $context): mixed
    {
        // Create backup point and start monitoring
        $backupId = $this->backup->createBackupPoint();
        $monitoringId = $this->monitor->startOperation($context);
        
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($context);
            
            // Execute with monitoring
            $result = $this->executeWithMonitoring($operation, $monitoringId);
            
            // Validate result
            $this->validateResult($result);
            
            DB::commit();
            $this->auditor->logSuccess($context, $result);
            
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->backup->restoreFromPoint($backupId);
            $this->auditor->logFailure($e, $context, $monitoringId);
            $this->handleSystemFailure($e, $context);
            
            throw new SystemFailureException(
                'Critical operation failed: ' . $e->getMessage(),
                previous: $e
            );
        } finally {
            $this->monitor->stopOperation($monitoringId);
            $this->cleanup($backupId, $monitoringId);
        }
    }

    protected function validateOperation(array $context): void
    {
        if (!$this->validator->validateContext($context)) {
            throw new ValidationException('Invalid operation context');
        }

        if (!$this->validator->checkSecurityConstraints($context)) {
            throw new SecurityException('Security constraints not met');
        }

        if (!$this->validator->verifySystemState()) {
            throw new SystemStateException('System state invalid for operation');
        }
    }

    protected function executeWithMonitoring(callable $operation, string $monitoringId): mixed
    {
        return $this->monitor->track($monitoringId, function() use ($operation) {
            return $operation();
        });
    }

    protected function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Operation result validation failed');
        }
    }

    protected function handleSystemFailure(\Throwable $e, array $context): void
    {
        // Log critical error with full context
        Log::critical('System failure occurred', [
            'exception' => $e,
            'context' => $context,
            'stack_trace' => $e->getTraceAsString(),
            'system_state' => $this->monitor->captureSystemState()
        ]);

        // Notify administrators
        $this->notifyAdministrators($e, $context);
        
        // Execute emergency protocols
        $this->executeEmergencyProtocols($e);
    }

    protected function cleanup(string $backupId, string $monitoringId): void
    {
        try {
            $this->backup->cleanupBackupPoint($backupId);
            $this->monitor->cleanupOperation($monitoringId);
        } catch (\Exception $e) {
            Log::error('Cleanup failed', [
                'exception' => $e,
                'backup_id' => $backupId,
                'monitoring_id' => $monitoringId
            ]);
        }
    }
}
