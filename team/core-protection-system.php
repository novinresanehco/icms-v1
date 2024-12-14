<?php

namespace App\Core\Protection;

use Illuminate\Support\Facades\{DB, Log, Cache};
use App\Core\Interfaces\{SecurityInterface, ValidationInterface, AuditInterface};
use App\Core\Exceptions\{SystemFailureException, ValidationException};

class CoreProtectionSystem implements SecurityInterface
{
    protected ValidationService $validator;
    protected AuditService $auditor;
    protected MonitoringService $monitor;
    protected BackupService $backup;

    public function executeProtectedOperation(callable $operation, array $context): mixed
    {
        $backupId = $this->backup->createBackupPoint();
        $monitoringId = $this->monitor->startOperation($context);
        
        DB::beginTransaction();
        
        try {
            $this->validateContext($context);
            $result = $this->executeWithMonitoring($operation, $monitoringId);
            $this->validateResult($result);
            
            DB::commit();
            $this->auditor->logSuccess($context, $result);
            
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->backup->restoreFromPoint($backupId);
            $this->handleFailure($e, $context, $monitoringId);
            
            throw new SystemFailureException(
                'Operation failed: ' . $e->getMessage(),
                previous: $e
            );
        } finally {
            $this->monitor->stopOperation($monitoringId);
            $this->cleanup($backupId, $monitoringId);
        }
    }

    protected function validateContext(array $context): void
    {
        if (!$this->validator->validateContext($context)) {
            throw new ValidationException('Invalid operation context');
        }

        if (!$this->validator->checkSecurityConstraints($context)) {
            throw new SecurityException('Security constraints not met');
        }

        if (!$this->validator->verifySystemState()) {
            throw new SystemStateException('System state invalid');
        }
    }

    protected function executeWithMonitoring(callable $operation, string $monitoringId): mixed
    {
        return $this->monitor->track($monitoringId, function() use ($operation) {
            $startTime = microtime(true);
            $result = $operation();
            $duration = microtime(true) - $startTime;
            
            $this->monitor->recordMetrics([
                'duration' => $duration,
                'memory' => memory_get_peak_usage(true),
                'cpu' => sys_getloadavg()[0]
            ]);
            
            return $result;
        });
    }

    protected function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Result validation failed');
        }
    }

    protected function handleFailure(\Throwable $e, array $context, string $monitoringId): void
    {
        Log::critical('System failure occurred', [
            'exception' => $e->getMessage(),
            'context' => $context,
            'trace' => $e->getTraceAsString(),
            'monitoring_id' => $monitoringId,
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
            Cache::tags(['operation', $monitoringId])->flush();
        } catch (\Exception $e) {
            Log::error('Cleanup failed', [
                'exception' => $e->getMessage(),
                'backup_id' => $backupId,
                'monitoring_id' => $monitoringId
            ]);
        }
    }

    protected function notifyAdministrators(\Throwable $e, array $context): void
    {
        if ($e instanceof CriticalException) {
            $this->emergencyNotification->send([
                'type' => 'critical_failure',
                'message' => $e->getMessage(),
                'context' => $context,
                'timestamp' => now()
            ]);
        }
    }

    protected function executeEmergencyProtocols(\Throwable $e): void
    {
        if ($this->isSystemCritical($e)) {
            $this->emergencyShutdown->execute([
                'reason' => $e->getMessage(),
                'timestamp' => now(),
                'recovery_plan' => $this->generateRecoveryPlan($e)
            ]);
        }
    }
}
