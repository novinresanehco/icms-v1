<?php

namespace App\Core\System;

/**
 * CRITICAL SYSTEM CONTROL
 * Zero-tolerance error framework
 */
class SystemManager {
    private SecurityService $security;
    private MonitoringService $monitor;
    private ValidationService $validator;
    private BackupService $backup;

    public function __construct(
        SecurityService $security,
        MonitoringService $monitor,
        ValidationService $validator,
        BackupService $backup
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->validator = $validator;
        $this->backup = $backup;
    }

    public function executeCriticalOperation(callable $operation, array $context): mixed 
    {
        // Pre-execution validation
        $this->validator->validateContext($context);
        
        // Create system snapshot
        $snapshotId = $this->backup->createSnapshot();
        
        // Initialize monitoring
        $monitorId = $this->monitor->startMonitoring();

        try {
            // Execute with full protection
            $result = $this->executeProtected($operation, $context);
            
            // Validate result
            $this->validator->validateResult($result);
            
            return $result;
            
        } catch (\Throwable $e) {
            // Restore system state
            $this->backup->restoreSnapshot($snapshotId);
            
            // Log failure with full context
            $this->logFailure($e, $context, $monitorId);
            
            throw new SystemFailureException(
                'Critical operation failed',
                previous: $e
            );
        } finally {
            $this->monitor->stopMonitoring($monitorId);
        }
    }

    private function executeProtected(callable $operation, array $context): mixed
    {
        return $this->security->executeSecure(function() use ($operation, $context) {
            return DB::transaction(function() use ($operation, $context) {
                return $operation($context);
            });
        });
    }

    private function logFailure(\Throwable $e, array $context, string $monitorId): void
    {
        Log::critical('System failure', [
            'exception' => $e,
            'context' => $context,
            'monitor_id' => $monitorId,
            'system_state' => $this->monitor->getSystemState()
        ]);
    }
}
