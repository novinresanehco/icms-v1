<?php

namespace App\Core\Control;

class CriticalControlSupervisor implements ControlSupervisorInterface
{
    private CommandValidator $commandValidator;
    private ControlEngine $controlEngine;
    private StateManager $stateManager;
    private SupervisorLogger $logger;
    private EmergencyProtocol $emergency;
    private AlertSystem $alerts;

    public function __construct(
        CommandValidator $commandValidator,
        ControlEngine $controlEngine,
        StateManager $stateManager,
        SupervisorLogger $logger,
        EmergencyProtocol $emergency,
        AlertSystem $alerts
    ) {
        $this->commandValidator = $commandValidator;
        $this->controlEngine = $controlEngine;
        $this->stateManager = $stateManager;
        $this->logger = $logger;
        $this->emergency = $emergency;
        $this->alerts = $alerts;
    }

    public function superviseControl(SupervisionContext $context): SupervisionResult
    {
        $supervisionId = $this->initializeSupervision($context);
        
        try {
            DB::beginTransaction();

            $this->validateControlCommands($context);
            $controlState = $this->controlEngine->getCurrentState();
            
            $this->validateControlState($controlState);
            $this->enforceControlLimits($controlState);

            $result = new SupervisionResult([
                'supervisionId' => $supervisionId,
                'controlState' => $controlState,
                'status' => SupervisionStatus::ACTIVE,
                'metrics' => $this->collectControlMetrics(),
                'timestamp' => now()
            ]);

            DB::commit();
            return $result;

        } catch (SupervisionException $e) {
            DB::rollBack();
            $this->handleSupervisionFailure($e, $supervisionId);
            throw new CriticalSupervisionException($e->getMessage(), $e);
        }
    }

    private function validateControlCommands(SupervisionContext $context): void
    {
        $validationResult = $this->commandValidator->validate($context->getCommands());
        
        if (!$validationResult->isPassed()) {
            $this->emergency->handleInvalidCommands($validationResult);
            throw new InvalidCommandException('Critical control command validation failed');
        }
    }

    private function validateControlState(ControlState $state): void
    {
        if (!$this->stateManager->validateState($state)) {
            $this->emergency->handleInvalidControlState($state);
            throw new InvalidStateException('Critical control state validation failed');
        }
    }

    private function enforceControlLimits(ControlState $state): void
    {
        $violations = $this->controlEngine->checkLimits($state);
        
        if (!empty($violations)) {
            $this->emergency->handleLimitViolations($violations);
            throw new LimitViolationException('Critical control limits exceeded');
        }
    }
}
