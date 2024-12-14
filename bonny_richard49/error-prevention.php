<?php

namespace App\Core\Protection;

use App\Core\Exceptions\SystemFailureException;
use Illuminate\Support\Facades\{DB, Log};

/**
 * Critical system protection layer
 * Implements zero-tolerance error prevention
 */
class SystemProtection
{
    private ValidationService $validator;
    private MonitoringService $monitor;
    private BackupService $backup;

    public function __construct(
        ValidationService $validator,
        MonitoringService $monitor,
        BackupService $backup
    ) {
        $this->validator = $validator;
        $this->monitor = $monitor;
        $this->backup = $backup;
    }

    /**
     * Execute operation with comprehensive protection
     * @throws SystemFailureException on any protection violation
     */
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
            // Execute with continuous monitoring
            $result = $this->executeWithMonitoring($operation, $monitoringId);
            
            // Validate result integrity
            $this->validateResult($result);
            
            DB::commit();
            
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            
            // Restore from backup if needed
            $this->backup->restoreFromPoint($backupId);
            
            // Log comprehensive error context
            Log::critical('System protection violation', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'context' => $context,
                'monitoring_id' => $monitoringId,
                'system_state' => $this->monitor->getSystemState()
            ]);
            
            throw new SystemFailureException(
                'Critical operation failed: ' . $e->getMessage(),
                previous: $e
            );
        } finally {
            $this->monitor->stopOperation($monitoringId);
            $this->backup->cleanupBackupPoint($backupId);
        }
    }

    protected function validateOperation(array $context): void
    {
        if (!$this->validator->validateContext($context)) {
            throw new ValidationException('Invalid operation context');
        }

        if (!$this->validator->checkSystemState()) {
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
}
