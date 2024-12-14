<?php

namespace App\Core\Protection;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Core\Security\SecurityManagerInterface;
use App\Core\Validation\ValidationServiceInterface;
use App\Core\Monitoring\MonitoringServiceInterface;
use App\Core\Backup\BackupServiceInterface;
use App\Core\Interfaces\CriticalOperationInterface;
use App\Core\Exceptions\{
    SystemFailureException,
    ValidationException,
    SecurityException
};

/**
 * Core Protection System for Critical CMS Operations
 * Implements comprehensive security, validation and monitoring
 */
class CoreProtectionSystem
{
    private SecurityManagerInterface $security;
    private ValidationServiceInterface $validator;
    private MonitoringServiceInterface $monitor;
    private BackupServiceInterface $backup;

    public function __construct(
        SecurityManagerInterface $security,
        ValidationServiceInterface $validator, 
        MonitoringServiceInterface $monitor,
        BackupServiceInterface $backup
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->monitor = $monitor;
        $this->backup = $backup;
    }

    /**
     * Execute critical operation with comprehensive protection
     *
     * @param CriticalOperationInterface $operation Operation to execute
     * @param array $context Operation context
     * @throws SystemFailureException If operation fails
     * @return mixed Operation result
     */
    public function executeProtectedOperation(
        CriticalOperationInterface $operation,
        array $context
    ): mixed {
        // Pre-operation validation
        $this->validateOperation($operation, $context);
        
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
            
            DB::commit();
            
            // Log successful operation
            $this->logSuccess($context, $result);
            
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            
            // Restore from backup if needed
            $this->backup->restoreFromPoint($backupId);
            
            // Log failure with full context
            $this->logFailure($e, $context, $monitoringId);
            
            // Handle error appropriately
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

    /**
     * Validate operation before execution
     */
    private function validateOperation(
        CriticalOperationInterface $operation,
        array $context
    ): void {
        // Validate operation requirements
        if (!$this->validator->validateOperation($operation, $context)) {
            throw new ValidationException('Operation validation failed');
        }

        // Validate security constraints
        if (!$this->security->checkSecurityConstraints($context)) {
            throw new SecurityException('Security constraints not met');
        }
    }

    /**
     * Execute operation with comprehensive monitoring
     */
    private function executeWithMonitoring(
        CriticalOperationInterface $operation,
        string $monitoringId
    ): mixed {
        return $this->monitor->track($monitoringId, function() use ($operation) {
            return $operation->execute();  
        });
    }

    /**
     * Validate operation result
     */
    private function validateResult($result): void {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Operation result validation failed');
        }
    }

    /**
     * Handle system failure with comprehensive logging
     */
    private function handleSystemFailure(\Throwable $e, array $context): void {
        // Log critical error
        Log::critical('System failure occurred', [
            'exception' => $e,
            'context' => $context,
            'stack_trace' => $e->getTraceAsString(),
            'system_state' => $this->monitor->captureSystemState()
        ]);

        // Notify system administrators
        $this->notifyAdministrators($e, $context);
        
        // Execute emergency protocols if needed
        $this->executeEmergencyProtocols($e);
    }

    /**
     * Log successful operation completion
     */
    private function logSuccess(array $context, $result): void {
        Log::info('Operation completed successfully', [
            'context' => $context,
            'result' => $result,
            'execution_time' => $this->monitor->getOperationTime()
        ]);
    }

    /**
     * Log operation failure with full context
     */
    private function logFailure(
        \Throwable $e,
        array $context,
        string $monitoringId
    ): void {
        Log::error('Operation failed', [
            'exception' => $e->getMessage(),
            'context' => $context,
            'monitoring_id' => $monitoringId,
            'system_state' => $this->monitor->captureSystemState()
        ]);
    }

    /**
     * Clean up resources after operation
     */
    private function cleanup(string $backupId, string $monitoringId): void {
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

    /**
     * Notify system administrators of failure
     */
    private function notifyAdministrators(\Throwable $e, array $context): void {
        // Implementation would depend on notification system
        // Must be handled without throwing exceptions
    }

    /**
     * Execute emergency protocols if needed
     */
    private function executeEmergencyProtocols(\Throwable $e): void {
        // Implementation would depend on system requirements
        // Must be handled without throwing exceptions
    }
}
