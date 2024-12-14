<?php

namespace App\Core\Recovery;

class RecoveryService implements RecoveryInterface
{
    private StateManager $stateManager;
    private BackupService $backupService;
    private ValidationService $validationService;
    private RecoveryLogger $logger;
    private MetricsCollector $metrics;
    private AlertSystem $alerts;

    public function __construct(
        StateManager $stateManager,
        BackupService $backupService,
        ValidationService $validationService,
        RecoveryLogger $logger,
        MetricsCollector $metrics,
        AlertSystem $alerts
    ) {
        $this->stateManager = $stateManager;
        $this->backupService = $backupService;
        $this->validationService = $validationService;
        $this->logger = $logger;
        $this->metrics = $metrics;
        $this->alerts = $alerts;
    }

    public function initiateRecovery(RecoveryContext $context): RecoveryResult
    {
        $recoveryId = $this->initializeRecovery($context);
        
        try {
            DB::beginTransaction();
            
            $this->validateRecoveryContext($context);
            $backupState = $this->loadBackupState($context);
            
            $this->verifyBackupIntegrity($backupState);
            $restoredState = $this->restoreState($backupState);
            
            $this->validateRestoredState($restoredState);
            
            DB::commit();
            
            $result = new RecoveryResult([
                'recovery_id' => $recoveryId,
                'state' => $restoredState,
                'timestamp' => now()
            ]);
            
            $this->finalizeRecovery($result);
            return $result;

        } catch (RecoveryException $e) {
            DB::rollBack();
            $this->handleRecoveryFailure($e, $recoveryId);
            throw new CriticalRecoveryException($e->getMessage(), $e);
        }
    }

    private function validateRecoveryContext(RecoveryContext $context): void
    {
        if (!$this->validationService->validateContext($context)) {
            throw new ValidationException('Recovery context validation failed');
        }
    }

    private function loadBackupState(RecoveryContext $context): BackupState
    {
        $backup = $this->backupService->loadBackup($context->getBackupId());
        
        if (!$backup) {
            throw new BackupNotFoundException('Backup state not found');
        }
        
        return $backup;
    }

    private function verifyBackupIntegrity(BackupState $state): void
    {
        if (!$this->validationService->verifyBackupIntegrity($state)) {
            throw new IntegrityException('Backup state integrity verification failed');
        }
    }

    private function restoreState(BackupState $backupState): SystemState
    {
        return $this->stateManager->restore($backupState);
    }

    private function validateRestoredState(SystemState $state): void
    {
        if (!$this->validationService->validateState($state)) {
            throw new ValidationException('Restored state validation failed');
        }
    }

    private function handleRecoveryFailure(RecoveryException $e, string $recoveryId): void
    {
        $this->logger->logFailure($e, $recoveryId);
        
        $this->alerts->dispatch(
            new RecoveryAlert(
                'Critical recovery failure',
                [
                    'recovery_id' => $recoveryId,
                    'exception' => $e
                ]
            )
        );
        
        $this->metrics->recordFailure('recovery', [
            'recovery_id' => $recoveryId,
            'error' => $e->getMessage()
        ]);
    }
}
