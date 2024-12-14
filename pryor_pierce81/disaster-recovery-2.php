<?php

namespace App\Core\Recovery;

class DisasterRecoveryService implements DisasterRecoveryInterface
{
    private RecoveryEngine $engine;
    private BackupValidator $validator;
    private SystemRestorer $restorer;
    private RecoveryLogger $logger;
    private EmergencyProtocol $emergency;
    private AlertSystem $alerts;

    public function __construct(
        RecoveryEngine $engine,
        BackupValidator $validator,
        SystemRestorer $restorer,
        RecoveryLogger $logger,
        EmergencyProtocol $emergency,
        AlertSystem $alerts
    ) {
        $this->engine = $engine;
        $this->validator = $validator;
        $this->restorer = $restorer;
        $this->logger = $logger;
        $this->emergency = $emergency;
        $this->alerts = $alerts;
    }

    public function initiateRecovery(RecoveryContext $context): RecoveryResult
    {
        $recoveryId = $this->initializeRecovery($context);
        
        try {
            DB::beginTransaction();

            $backup = $this->loadBackup($context->getBackupId());
            $this->validateBackup($backup);

            $recoveryPlan = $this->engine->createRecoveryPlan($backup);
            $this->validateRecoveryPlan($recoveryPlan);

            $restorationResult = $this->restorer->restoreSystem($recoveryPlan);
            $this->verifyRestoration($restorationResult);

            $result = new RecoveryResult([
                'recoveryId' => $recoveryId,
                'backup' => $backup->getMetadata(),
                'restoration' => $restorationResult,
                'status' => RecoveryStatus::COMPLETED,
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (RecoveryException $e) {
            DB::rollBack();
            $this->handleRecoveryFailure($e, $recoveryId);
            throw new CriticalRecoveryException($e->getMessage(), $e);
        }
    }

    private function validateBackup(Backup $backup): void
    {
        if (!$this->validator->validateBackup($backup)) {
            $this->emergency->handleInvalidBackup($backup);
            throw new InvalidBackupException('Backup validation failed');
        }
    }

    private function validateRecoveryPlan(RecoveryPlan $plan): void
    {
        $validationResult = $this->validator->validatePlan($plan);
        
        if (!$validationResult->isValid()) {
            $this->emergency->handleInvalidRecoveryPlan($validationResult);
            throw new InvalidRecoveryPlanException('Recovery plan validation failed');
        }
    }

    private function verifyRestoration(RestorationResult $result): void
    {
        if (!$result->isSuccessful()) {
            $this->emergency->handleFailedRestoration($result);
            throw new RestorationFailedException('System restoration failed');
        }
    }
}
