<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{Cache, Log, DB};
use App\Core\Exceptions\SecurityException;

class CoreSecurityManager implements SecurityManagerInterface 
{
    protected ValidationService $validator;
    protected EncryptionService $encryption;
    protected AuditLogger $audit;
    protected MonitoringService $monitor;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $audit,
        MonitoringService $monitor
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->audit = $audit;
        $this->monitor = $monitor;
    }

    public function executeCriticalOperation(callable $operation, SecurityContext $context): mixed
    {
        $monitoringId = $this->monitor->startOperation($context);
        $backupId = null;

        try {
            // Pre-execution validation
            $this->validateOperation($context);
            
            // Create backup point
            $backupId = $this->createBackupPoint();

            DB::beginTransaction();

            // Execute with monitoring
            $result = $this->monitor->track($monitoringId, function() use ($operation, $context) {
                return $operation($context);
            });

            // Validate result
            $this->validateResult($result);

            DB::commit();
            $this->audit->logSuccess($context, $result);

            return $result;

        } catch (\Throwable $e) {
            DB::rollBack();

            if ($backupId) {
                $this->restoreBackupPoint($backupId);
            }

            $this->handleFailure($e, $context, $monitoringId);
            throw new SecurityException('Critical operation failed', 0, $e);

        } finally {
            $this->monitor->stopOperation($monitoringId);
            $this->cleanup($backupId, $monitoringId);
        }
    }

    protected function validateOperation(SecurityContext $context): void
    {
        if (!$this->validator->validateContext($context)) {
            throw new ValidationException('Invalid operation context');
        }

        if (!$this->validator->verifySecurityConstraints($context)) {
            throw new SecurityConstraintException('Security constraints not met');
        }

        if (!$this->validator->checkSystemState()) {
            throw new SystemStateException('System state invalid for operation');
        }
    }

    protected function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Operation result validation failed');
        }
    }

    protected function handleFailure(\Throwable $e, SecurityContext $context, string $monitoringId): void
    {
        $this->audit->logFailure($e, $context, $monitoringId);
        $this->notifyAdministrators($e, $context);

        if ($this->isEmergencySituation($e)) {
            $this->executeEmergencyProtocols($e);
        }
    }

    protected function createBackupPoint(): string
    {
        return $this->monitor->createBackup();
    }

    protected function restoreBackupPoint(string $backupId): void
    {
        $this->monitor->restoreBackup($backupId);
    }

    protected function cleanup(string $backupId = null, string $monitoringId = null): void
    {
        try {
            if ($backupId) {
                $this->monitor->cleanupBackup($backupId);
            }
            if ($monitoringId) {
                $this->monitor->cleanupOperation($monitoringId);
            }
        } catch (\Exception $e) {
            Log::error('Cleanup failed', [
                'exception' => $e,
                'backup_id' => $backupId,
                'monitoring_id' => $monitoringId
            ]);
        }
    }

    protected function isEmergencySituation(\Throwable $e): bool
    {
        return $e instanceof CriticalSecurityException || 
               $e instanceof SystemFailureException || 
               $e instanceof DataCorruptionException;
    }
}
