<?php

namespace App\Core\State;

class StateMachineController implements StateMachineInterface
{
    private StateRegistry $registry;
    private TransitionValidator $validator;
    private EventProcessor $processor;
    private StateLogger $logger;
    private EmergencyProtocol $emergency;
    private AlertSystem $alerts;

    public function __construct(
        StateRegistry $registry,
        TransitionValidator $validator,
        EventProcessor $processor,
        StateLogger $logger,
        EmergencyProtocol $emergency,
        AlertSystem $alerts
    ) {
        $this->registry = $registry;
        $this->validator = $validator;
        $this->processor = $processor;
        $this->logger = $logger;
        $this->emergency = $emergency;
        $this->alerts = $alerts;
    }

    public function processStateTransition(TransitionContext $context): TransitionResult
    {
        $transitionId = $this->initializeTransition($context);
        
        try {
            DB::beginTransaction();

            $currentState = $this->registry->getCurrentState();
            $targetState = $context->getTargetState();

            $this->validateTransition($currentState, $targetState);
            $events = $this->processTransitionEvents($currentState, $targetState);
            $this->verifyTransitionResults($events);

            $result = new TransitionResult([
                'transitionId' => $transitionId,
                'previousState' => $currentState,
                'newState' => $targetState,
                'events' => $events,
                'metrics' => $this->collectMetrics(),
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (StateTransitionException $e) {
            DB::rollBack();
            $this->handleTransitionFailure($e, $transitionId);
            throw new CriticalStateException($e->getMessage(), $e);
        }
    }

    private function validateTransition(State $current, State $target): void
    {
        $validationResult = $this->validator->validateTransition($current, $target);
        
        if (!$validationResult->isValid()) {
            $this->emergency->handleInvalidTransition($validationResult);
            throw new InvalidTransitionException(
                'Invalid state transition detected',
                ['violations' => $validationResult->getViolations()]
            );
        }
    }

    private function processTransitionEvents(State $current, State $target): array
    {
        $events = $this->processor->processEvents($current, $target);
        
        if (!$this->processor->eventsSuccessful($events)) {
            throw new EventProcessingException('Failed to process transition events');
        }
        
        return $events;
    }

    private function handleTransitionFailure(
        StateTransitionException $e,
        string $transitionId
    ): void {
        $this->logger->logFailure($e, $transitionId);
        
        if ($e->isCritical()) {
            $this->emergency->initiateEmergencyProtocol();
            $this->alerts->dispatchCriticalAlert(
                new StateTransitionFailureAlert($e, $transitionId)
            );
        }
    }
}
