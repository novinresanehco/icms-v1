<?php

namespace App\Core\Failover;

class FailoverControlService implements FailoverControlInterface
{
    private FailoverOrchestrator $orchestrator;
    private SystemValidator $validator;
    private StateTransitioner $transitioner;
    private FailoverLogger $logger;
    private EmergencyProtocol $emergency;
    private AlertSystem $alerts;

    public function __construct(
        FailoverOrchestrator $orchestrator,
        SystemValidator $validator,
        StateTransitioner $transitioner,
        FailoverLogger $logger,
        EmergencyProtocol $emergency,
        AlertSystem $alerts
    ) {
        $this->orchestrator = $orchestrator;
        $this->validator = $validator;
        $this->transitioner = $transitioner;
        $this->logger = $logger;
        $this->emergency = $emergency;
        $this->alerts = $alerts;
    }

    public function initiateFailover(FailoverContext $context): FailoverResult
    {
        $failoverId = $this->initializeFailover($context);
        
        try {
            DB::beginTransaction();

            $this->validateFailoverContext($context);
            $primaryState = $this->validator->validatePrimarySystem();
            
            $failoverPlan = $this->orchestrator->createFailoverPlan($context, $primaryState);
            $this->validateFailoverPlan($failoverPlan);

            $secondaryState = $this->executeFailover($failoverPlan);
            $this->verifyFailoverState($secondaryState);

            $result = new FailoverResult([
                'failoverId' => $failoverId,
                'primaryState' => $primaryState,
                'secondaryState' => $secondaryState,
                'metrics' => $this->collectMetrics(),
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (FailoverException $e) {
            DB::rollBack();
            $this->handleFailoverFailure($e, $failoverId);
            throw new CriticalFailoverException($e->getMessage(), $e);
        }
    }

    private function executeFailover(FailoverPlan $plan): SystemState
    {
        $this->alerts->notifyFailoverInitiation($plan);
        
        $secondaryState = $this->transitioner->transition($plan);
        
        if (!$secondaryState->isOperational()) {
            $this->emergency->handleFailedFailover($secondaryState);
            throw new FailoverTransitionException('Failover transition failed');
        }
        
        return $secondaryState;
    }

    private function verifyFailoverState(SystemState $state): void
    {
        $verificationResult = $this->validator->verifySecondarySystem($state);
        
        if (!$verificationResult->isValid()) {
            $this->emergency->handleInvalidFailoverState($verificationResult);
            throw new InvalidFailoverStateException('Failover state verification failed');
        }
    }

    private function handleFailoverFailure(
        FailoverException $e,
        string $failoverId
    ): void {
        $this->logger->logFailure($e, $failoverId);
        $this->alerts->dispatchCriticalAlert(
            new FailoverFailureAlert($e, $failoverId)
        );
        $this->emergency->escalateToHighestLevel();
    }
}
