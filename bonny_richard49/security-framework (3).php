<?php

namespace App\Core\Security;

use App\Core\Contracts\SecurityManagerInterface;
use App\Core\Services\{ValidationService, EncryptionService, AuditService};
use Illuminate\Support\Facades\{Cache, DB, Log};

class SecurityManager implements SecurityManagerInterface 
{
    protected ValidationService $validator;
    protected EncryptionService $encryption;
    protected AuditService $audit;
    
    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption, 
        AuditService $audit
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->audit = $audit;
    }

    public function executeSecureOperation(callable $operation, array $context): mixed
    {
        // Create monitoring context
        $monitoringId = $this->startOperationMonitoring($context);
        
        // Backup critical state
        $backupId = $this->createStateBackup();
        
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validatePreConditions($context);
            
            // Execute with comprehensive monitoring
            $result = $this->executeWithProtection($operation, $monitoringId);
            
            // Validate result integrity
            $this->validateResult($result);
            
            DB::commit();
            
            // Log successful operation
            $this->audit->logSuccess($context, $result, $monitoringId);
            
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            
            // Restore critical state if needed
            $this->restoreState($backupId);
            
            // Log failure with full context
            $this->audit->logFailure($e, $context, $monitoringId);
            
            throw $e;
        } finally {
            // Cleanup monitoring resources
            $this->stopOperationMonitoring($monitoringId);
        }
    }

    protected function validatePreConditions(array $context): void
    {
        if (!$this->validator->validateContext($context)) {
            throw new SecurityException('Invalid operation context');
        }

        if (!$this->validator->checkSecurityConstraints($context)) {
            throw new SecurityException('Security constraints not met');
        }

        if (!$this->validator->verifySystemState()) {
            throw new SystemStateException('System state invalid for operation');
        }
    }

    protected function executeWithProtection(callable $operation, string $monitoringId): mixed
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

        if (!$this->validator->verifyResultIntegrity($result)) {
            throw new IntegrityException('Result integrity validation failed');
        }
    }

    protected function startOperationMonitoring(array $context): string
    {
        return $this->monitor->startOperation([
            'type' => $context['operation_type'],
            'user' => $context['user_id'] ?? null,
            'ip' => request()->ip(),
            'timestamp' => now(),
            'resource' => $context['resource'] ?? null
        ]);
    }

    protected function createStateBackup(): string
    {
        return $this->backup->createBackupPoint();
    }

    protected function restoreState(string $backupId): void
    {
        try {
            $this->backup->restoreFromPoint($backupId);
        } catch (\Exception $e) {
            Log::critical('State restoration failed', [
                'backup_id' => $backupId,
                'exception' => $e
            ]);
        }
    }

    protected function stopOperationMonitoring(string $monitoringId): void
    {
        try {
            $this->monitor->stopOperation($monitoringId);
        } catch (\Exception $e) {
            Log::error('Failed to stop monitoring', [
                'monitoring_id' => $monitoringId,
                'exception' => $e
            ]);
        }
    }
}
