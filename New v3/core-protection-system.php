<?php

namespace App\Core\Protection;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Core\Exceptions\SystemFailureException;
use App\Core\Interfaces\{SecurityInterface, ValidationInterface, AuditInterface};

/**
 * Core protection system handling all critical operations with comprehensive
 * validation, monitoring and protection.
 */
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
        // Pre-execution validation
        $this->validateOperation($context);
        
        // Create backup point
        $backupId = $this->backup->createBackupPoint();
        
        // Start monitoring
        $monitoringId = $this->monitor->startOperation($context);
        
        DB::beginTransaction();
        
        try {
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

    protected function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Operation result validation failed');
        }
    }

    protected function handleSystemFailure(\Throwable $e, array $context): void
    {
        Log::critical('System failure occurred', [
            'exception' => $e,
            'context' => $context,
            'stack_trace' => $e->getTraceAsString(),
            'system_state' => $this->monitor->captureSystemState()
        ]);

        $this->notifyAdministrators($e, $context);
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
