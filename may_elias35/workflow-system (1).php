// File: app/Core/Workflow/Manager/WorkflowManager.php
<?php

namespace App\Core\Workflow\Manager;

class WorkflowManager
{
    protected StateManager $stateManager;
    protected TransitionManager $transitionManager;
    protected WorkflowValidator $validator;
    protected EventDispatcher $events;

    public function execute(Workflow $workflow, array $data): WorkflowResult
    {
        $this->validator->validate($workflow);
        
        DB::beginTransaction();
        try {
            $state = $this->stateManager->start($workflow, $data);
            
            while (!$state->isFinal()) {
                $transition = $this->transitionManager->getNextTransition($state);
                $state = $this->executeTransition($transition, $state);
            }
            
            DB::commit();
            return new WorkflowResult($state);
        } catch (\Exception $e) {
            DB::rollBack();
            throw new WorkflowException("Workflow execution failed: " . $e->getMessage());
        }
    }

    public function getCurrentState(Workflow $workflow): State
    {
        return $this->stateManager->getCurrentState($workflow);
    }

    protected function executeTransition(Transition $transition, State $state): State
    {
        $this->events->dispatch(new TransitionStarted($transition, $state));
        $newState = $transition->execute($state);
        $this->events->dispatch(new TransitionCompleted($transition, $newState));
        
        return $newState;
    }
}

// File: app/Core/Workflow/State/StateManager.php
<?php

namespace App\Core\Workflow\State;

class StateManager
{
    protected StateRepository $repository;
    protected StateFactory $factory;
    protected StateValidator $validator;

    public function start(Workflow $workflow, array $data): State
    {
        $initialState = $workflow->getInitialState();
        return $this->createState($workflow, $initialState, $data);
    }

    public function transition(State $currentState, Transition $transition): State
    {
        $this->validator->validateTransition($currentState, $transition);
        
        $newState = $this->createState(
            $currentState->getWorkflow(),
            $transition->getTargetState(),
            $transition->execute($currentState->getData())
        );

        $this->repository->save($newState);
        return $newState;
    }

    protected function createState(Workflow $workflow, string $name, array $data): State
    {
        $state = $this->factory->create([
            'workflow' => $workflow,
            'name' => $name,
            'data' => $data,
            'created_at' => now()
        ]);

        $this->repository->save($state);
        return $state;
    }
}

// File: app/Core/Workflow/Transition/TransitionManager.php
<?php

namespace App\Core\Workflow\Transition;

class TransitionManager
{
    protected TransitionRepository $repository;
    protected ConditionEvaluator $evaluator;
    protected TransitionConfig $config;

    public function getNextTransition(State $state): Transition
    {
        $transitions = $this->repository->getAvailableTransitions($state);
        
        foreach ($transitions as $transition) {
            if ($this->canTransition($state, $transition)) {
                return $transition;
            }
        }

        throw new NoValidTransitionException("No valid transition found for state: " . $state->getName());
    }

    public function canTransition(State $state, Transition $transition): bool
    {
        if ($transition->getSourceState() !== $state->getName()) {
            return false;
        }

        return $this->evaluator->evaluate($transition->getConditions(), $state->getData());
    }
}

// File: app/Core/Workflow/History/HistoryManager.php
<?php

namespace App\Core\Workflow\History;

class HistoryManager
{
    protected HistoryRepository $repository;
    protected HistoryFormatter $formatter;
    protected HistoryConfig $config;

    public function record(Workflow $workflow, State $state, ?Transition $transition = null): void
    {
        $entry = new HistoryEntry([
            'workflow_id' => $workflow->getId(),
            'state' => $state->getName(),
            'transition' => $transition ? $transition->getName() : null,
            'data' => $state->getData(),
            'timestamp' => now()
        ]);

        $this->repository->save($entry);
    }

    public function getHistory(Workflow $workflow): array
    {
        $entries = $this->repository->findByWorkflow($workflow);
        return $this->formatter->format($entries);
    }

    public function getStateHistory(Workflow $workflow, string $state): array
    {
        return $this->repository->findByWorkflowAndState($workflow, $state);
    }
}
