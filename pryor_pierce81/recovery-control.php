<?php

namespace App\Core\Recovery;

class RecoveryControlService implements RecoveryControlInterface
{
    private StateManager $stateManager;
    private RecoveryValidator $validator;
    private BackupOrchestrator $orchestrator;
    private HealthVerifier $healthVerifier;
    private RecoveryLogger $logger;
    private EmergencyProtocol $emergency;

    public function __construct(
        StateManager $stateManager,
        RecoveryValidator $validator,
        BackupOrchestrator $orchestrator,
        HealthVerifier $healthVerifier,
        RecoveryLogger $logger,
        EmergencyProtocol $emergency
    ) {
        $this->stateManager = $stateManager;
        $this->validator = $validator;
        $this->orchestrator = $orchestrator;
        $this->healthVerifier = $healthVerifier;
        $this->logger = $logger;
        $this->emergency = $emergency;
    }

    public function executeRecovery(RecoveryContext $context): RecoveryResult
    {
        $recoveryId = $this->initializeRecovery($context);
        
        try {
            DB::beginTransaction();

            $this->validateRecoveryContext($context);
            $currentState = $this->stateManager->captureState();
            
            $recoveryPlan = $this->orchestrator->createRecoveryPlan($context, $currentState);
            $this->validateRecoveryPlan($recoveryPlan);

            $recoveredState = $this->executeRecoveryPlan($recoveryPlan);
            $this->verifyRecoveredState($recoveredState);

            $result = new RecoveryResult([
                'recoveryId' => $recoveryId,
                'originalState' => $currentState,
                'recoveredState' => $recoveredState,
                'metrics' => $this->collectMetrics(),
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

    private function validateRecoveryPlan(RecoveryPlan $plan): void
    {
        if (!$this->validator->validatePlan($plan)) {
            $this->emergency->handleInvalidRecoveryPlan($plan);
            throw new InvalidRecoveryPlanException('Recovery plan validation failed');
        }
    }

    private function executeRecoveryPlan(RecoveryPlan $plan): SystemState
    {
        $recoveryState = $this->orchestrator->executeRecovery($plan);
        
        if (!$recoveryState->isValid()) {
            $this->emergency->handleFailedRecovery($recoveryState);
            throw new RecoveryExecutionException('Recovery execution failed');
        }
        
        return $recoveryState;
    }

    private function verifyRecoveredState(SystemState $state): void
    {
        $healthStatus = $this->healthVerifier->verifyHealth($state);
        
        if (!$healthStatus->isHealthy()) {
            $this->emergency->handleUnhealthyRecovery($healthStatus);
            throw new UnhealthyRecoveryException('Recovered state verification failed');
        }
    }
}
