<?php

namespace App\Core\Security;

/**
 * Core security manager handling critical system operations with comprehensive protection
 */
class CoreSecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private MonitoringService $monitor;
    private AuditLogger $logger;
    private BackupService $backup;
    private SecurityConfig $config;

    public function __construct(
        ValidationService $validator,
        MonitoringService $monitor,
        AuditLogger $logger,
        BackupService $backup,
        SecurityConfig $config
    ) {
        $this->validator = $validator;
        $this->monitor = $monitor;
        $this->logger = $logger;
        $this->backup = $backup;
        $this->config = $config;
    }

    /**
     * Execute a critical operation with comprehensive protection and monitoring
     *
     * @throws SecurityException
     */
    public function executeCriticalOperation(CriticalOperation $operation): OperationResult 
    {
        // Create backup point
        $backupId = $this->backup->createBackupPoint();
        
        // Start monitoring 
        $monitorId = $this->monitor->startOperation();
        
        DB::beginTransaction();
        
        try {
            // Pre-operation validation
            $this->validateOperation($operation);
            
            // Execute with monitoring
            $result = $this->executeWithMonitoring($operation);
            
            // Validate result
            $this->validateResult($result);
            
            DB::commit();
            $this->logger->logSuccess($operation, $result);
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->backup->restoreFromPoint($backupId);
            $this->logger->logFailure($e, $operation);
            throw new SecurityException('Operation failed', 0, $e);
        } finally {
            $this->monitor->stopOperation($monitorId);
            $this->backup->cleanupBackupPoint($backupId);
        }
    }

    private function validateOperation(CriticalOperation $operation): void
    {
        if (!$this->validator->validateOperation($operation)) {
            throw new ValidationException('Invalid operation');
        }

        if (!$this->validator->checkSecurityConstraints($operation)) {
            throw new SecurityException('Security constraints not met');
        }

        if (!$this->validator->verifySystemState()) {
            throw new SystemStateException('System state invalid');
        }
    }

    private function executeWithMonitoring(CriticalOperation $operation): OperationResult
    {
        return $this->monitor->track($operation->getId(), function() use ($operation) {
            return $operation->execute();
        });
    }

    private function validateResult(OperationResult $result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Result validation failed');
        }
    }
}
