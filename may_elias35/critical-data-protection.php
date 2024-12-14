<?php

namespace App\Core\Protection;

use App\Core\Security\SecurityManager;
use App\Core\Monitoring\SystemMonitor;
use App\Core\Validation\ValidationService;
use App\Core\Storage\StorageManager;
use App\Core\Exceptions\{
    DataProtectionException,
    ValidationException,
    SecurityException
};

/**
 * Core Data Protection Manager
 * CRITICAL COMPONENT - Handles all data protection operations
 * Any changes require security team approval
 */
class DataProtectionManager implements DataProtectionInterface 
{
    private SecurityManager $security;
    private SystemMonitor $monitor;
    private ValidationService $validator;
    private StorageManager $storage;
    private array $config;

    public function __construct(
        SecurityManager $security,
        SystemMonitor $monitor,
        ValidationService $validator,
        StorageManager $storage,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->validator = $validator;
        $this->storage = $storage;
        $this->config = $config;
    }

    /**
     * Executes a protected data operation with comprehensive security controls
     *
     * @param DataOperation $operation The data operation to execute
     * @param array $context Operation context including security metadata
     * @throws DataProtectionException If protection cannot be guaranteed
     * @return OperationResult
     */
    public function executeProtectedOperation(
        DataOperation $operation,
        array $context
    ): OperationResult {
        $monitoringId = $this->monitor->startOperation('data_protection');
        
        try {
            // Pre-operation validation
            $this->validateOperation($operation, $context);
            
            // Create secure backup point
            $backupId = $this->createSecureBackup($operation);
            
            // Execute with protection
            $result = $this->executeWithProtection($operation, $context);
            
            // Verify result integrity
            $this->verifyResultIntegrity($result);
            
            // Record successful operation
            $this->recordSuccess($monitoringId, $operation);
            
            return $result;
            
        } catch (\Exception $e) {
            // Handle failure with full protection
            $this->handleProtectionFailure($e, $operation, $monitoringId);
            throw new DataProtectionException(
                'Protected operation failed: ' . $e->getMessage(),
                0,
                $e
            );
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    /**
     * Validates operation before execution
     */
    private function validateOperation(
        DataOperation $operation,
        array $context
    ): void {
        // Validate input data
        if (!$this->validator->validateData($operation->getData())) {
            throw new ValidationException('Invalid operation data');
        }

        // Validate security context
        if (!$this->security->validateContext($context)) {
            throw new SecurityException('Invalid security context');
        }

        // Verify system state
        if (!$this->monitor->verifySystemState()) {
            throw new DataProtectionException('System state invalid for operation');
        }
    }

    /**
     * Executes operation with comprehensive protection
     */
    private function executeWithProtection(
        DataOperation $operation,
        array $context
    ): OperationResult {
        return DB::transaction(function() use ($operation, $context) {
            // Apply security controls
            $this->security->applyOperationControls($operation);
            
            // Execute with monitoring
            $result = $operation->execute();
            
            // Verify execution integrity
            $this->verifyExecutionIntegrity($operation, $result);
            
            return $result;
        });
    }

    /**
     * Creates secure backup before operation
     */
    private function createSecureBackup(DataOperation $operation): string 
    {
        $backupId = $this->storage->createSecureBackup(
            $operation->getAffectedData(),
            $this->config['backup_encryption_level']
        );

        if (!$this->storage->verifyBackup($backupId)) {
            throw new DataProtectionException('Backup verification failed');
        }

        return $backupId;
    }

    /**
     * Verifies integrity of operation result
     */
    private function verifyResultIntegrity(OperationResult $result): void
    {
        // Verify data integrity
        if (!$this->validator->verifyIntegrity($result->getData())) {
            throw new DataProtectionException('Result integrity check failed');
        }

        // Verify security properties
        if (!$this->security->verifyResultSecurity($result)) {
            throw new SecurityException('Result security verification failed');
        }
    }

    /**
     * Records successful operation details
     */
    private function recordSuccess(
        string $monitoringId,
        DataOperation $operation
    ): void {
        $this->monitor->recordSuccess($monitoringId, [
            'operation_type' => $operation->getType(),
            'affected_data' => $operation->getAffectedData(),
            'execution_time' => $operation->getExecutionTime(),
            'security_level' => $operation->getSecurityLevel()
        ]);
    }

    /**
     * Handles operation failure with full protection
     */
    private function handleProtectionFailure(
        \Exception $e,
        DataOperation $operation,
        string $monitoringId
    ): void {
        // Record failure details
        $this->monitor->recordFailure($monitoringId, [
            'exception' => $e->getMessage(),
            'operation' => $operation->toArray(),
            'trace' => $e->getTraceAsString()
        ]);

        // Attempt recovery if possible
        if ($operation->isRecoverable()) {
            $this->attemptRecovery($operation);
        }

        // Notify security team
        $this->security->notifyFailure($e, $operation);
    }

    /**
     * Attempts to recover from operation failure
     */
    private function attemptRecovery(DataOperation $operation): void
    {
        try {
            $this->storage->restoreFromBackup(
                $operation->getBackupId(),
                $this->config['recovery_validation_level']
            );
        } catch (\Exception $e) {
            // Log recovery failure but don't throw
            $this->monitor->logRecoveryFailure($e);
        }
    }
}
