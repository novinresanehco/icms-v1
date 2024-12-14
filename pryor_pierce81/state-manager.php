<?php

namespace App\Core\State;

class StateManager implements StateInterface
{
    private StateStore $stateStore;
    private TransitionValidator $transitionValidator;
    private IntegrityChecker $integrityChecker;
    private StateLogger $logger;
    private AlertService $alertService;

    public function __construct(
        StateStore $stateStore,
        TransitionValidator $transitionValidator,
        IntegrityChecker $integrityChecker,
        StateLogger $logger,
        AlertService $alertService
    ) {
        $this->stateStore = $stateStore;
        $this->transitionValidator = $transitionValidator;
        $this->integrityChecker = $integrityChecker;
        $this->logger = $logger;
        $this->alertService = $alertService;
    }

    public function transitionState(StateTransition $transition): TransitionResult
    {
        $transitionId = $this->initializeTransition($transition);
        
        try {
            DB::beginTransaction();
            
            $currentState = $this->stateStore->getCurrentState();
            
            $this->validateTransition($transition, $currentState);
            $newState = $this->executeTransition($transition, $currentState);
            $this->verifyStateIntegrity($newState);
            
            $this->stateStore->storeState($newState);
            
            $result = new TransitionResult([
                'transitionId' => $transitionId,
                'previousState' => $currentState,
                'newState' => $newState,
                'timestamp' => now()
            ]);
            
            DB::commit();
            $this->finalizeTransition($result);
            
            return $result;

        } catch (StateException $e) {
            DB::rollBack();
            $this->handleTransitionFailure($e, $transitionId);
            throw new CriticalStateException($e->getMessage(), $e);
        }
    }

    private function validateTransition(
        StateTransition $transition,
        SystemState $currentState
    ): void {
        if (!$this->transitionValidator->validate($transition, $currentState)) {
            throw new InvalidTransitionException(
                'Invalid state transition detected'
            );
        }
    }

    private function verifyStateIntegrity(SystemState $state): void
    {
        if (!$this->integrityChecker->verifyIntegrity($state)) {
            throw new StateIntegrityException(
                'State integrity verification failed'
            );
        }
    }

    private function finalizeTransition(TransitionResult $result): void
    {
        $this->logger->logTransition($result);
        
        if ($result->newState->criticality >= StateCriticality::HIGH) {
            $this->alertService->sendAlert(
                new CriticalStateAlert($result)
            );
        }
    }
}
