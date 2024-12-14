<?php

namespace App\Core\Protection;

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

    public function executeProtectedOperation(callable $operation, array $context): mixed
    {
        // Pre-operation validation
        $this->validateOperation($context);
        
        // Create backup point
        $backupId = $this->backup->createBackupPoint();
        
        // Start monitoring
        $monitoringId = $this->monitor->startOperation($context);
        
        DB::beginTransaction();
        
        try {
            // Execute operation with monitoring
            $result = $this->executeWithMonitoring($operation, $monitoringId);
            
            // Validate result
            $this->validateResult($result);
            
            // Commit if all validations pass
            DB::commit();
            
            // Log successful operation
            $this->auditor->logSuccess($context, $result);
            
            return $result;
            
        } catch (\Throwable $e) {
            // Rollback transaction
            DB::rollBack();
            
            // Restore from backup if needed
            $this->backup->restoreFromPoint($backupId);
            
            // Log failure with full context
            $this->auditor->logFailure($e, $context, $monitoringId);
            
            // Handle the error appropriately
            $this->handleSystemFailure($e, $context);
            
            throw new SystemFailureException(
                'Critical operation failed: ' . $e->getMessage(),
                previous: $e
            );
        } finally {
            // Always stop monitoring
            $this->monitor->stopOperation($monitoringId);
            
            // Clean up temporary resources
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
        // Log critical error
        Log::critical('System failure occurred', [
            'exception' => $e,
            'context' => $context,
            'stack_trace' => $e->getTraceAsString(),
            'system_state' => $this->monitor->captureSystemState()
        ]);

        // Execute emergency protocols if needed
        $this->executeEmergencyProtocols($e);
    }

    protected function cleanup(string $backupId, string $monitoringId): void
    {
        try {
            $this->backup->cleanupBackupPoint($backupId);
            $this->monitor->cleanupOperation($monitoringId);
        } catch (\Exception $e) {
            // Log cleanup failure but don't throw
            Log::error('Cleanup failed', [
                'exception' => $e,
                'backup_id' => $backupId,
                'monitoring_id' => $monitoringId
            ]);
        }
    }
}
