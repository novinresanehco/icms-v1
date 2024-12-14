<?php

namespace App\Core\Workflow;

class WorkflowEngine
{
    private WorkflowRepository $repository;
    private StateManager $stateManager;
    private TransitionValidator $validator;
    private array $workflows = [];

    public function registerWorkflow(Workflow $workflow): void
    {
        $this->workflows[$workflow->getId()] = $workflow;
        $this->repository->save($workflow);
    }

    public function executeTransition(string $workflowId, string $processId, string $transition): void
    {
        $workflow = $this->workflows[$workflowId];
        $currentState = $this->stateManager->getCurrentState($processId);
        
        if (!$this->validator->canTransition($workflow, $currentState, $transition)) {
            throw new WorkflowException("Invalid transition: $transition");
        }

        $nextState = $workflow->getNextState($currentState, $transition);
        $this->stateManager->transition($processId, $currentState, $nextState);
    }

    public function startProcess(string $workflowId): string
    {
        $workflow = $this->workflows[$workflowId];
        $processId = uniqid('process_', true);
        
        $this->stateManager->initializeState($processId, $workflow->getInitialState());
        
        return $processId;
    }

    public function getAvailableTransitions(string $workflowId, string $processId): array
    {
        $workflow = $this->workflows[$workflowId];
        $currentState = $this->stateManager->getCurrentState($processId);
        
        return $workflow->getAvailableTransitions($currentState);
    }
}

class Workflow
{
    private string $id;
    private string $name;
    private array $states = [];
    private array $transitions = [];
    private string $initialState;

    public function __construct(string $name, string $initialState)
    {
        $this->id = uniqid('workflow_', true);
        $this->name = $name;
        $this->initialState = $initialState;
    }

    public function addState(State $state): void
    {
        $this->states[$state->getName()] = $state;
    }

    public function addTransition(Transition $transition): void
    {
        $this->transitions[] = $transition;
    }

    public function getNextState(string $currentState, string $transition): string
    {
        foreach ($this->transitions as $t) {
            if ($t->getFrom() === $currentState && $t->getName() === $transition) {
                return $t->getTo();
            }
        }
        throw new WorkflowException("No valid transition found");
    }

    public function getAvailableTransitions(string $currentState): array
    {
        return array_filter(
            $this->transitions,
            fn($t) => $t->getFrom() === $currentState
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getStates(): array
    {
        return $this->states;
    }

    public function getInitialState(): string
    {
        return $this->initialState;
    }
}

class State
{
    private string $name;
    private array $metadata;
    private array $actions = [];

    public function __construct(string $name, array $metadata = [])
    {
        $this->name = $name;
        $this->metadata = $metadata;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function addAction(Action $action): void
    {
        $this->actions[] = $action;
    }

    public function getActions(): array
    {
        return $this->actions;
    }
}

class Transition
{
    private string $name;
    private string $from;
    private string $to;
    private array $guards = [];

    public function __construct(string $name, string $from, string $to)
    {
        $this->name = $name;
        $this->from = $from;
        $this->to = $to;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getFrom(): string
    {
        return $this->from;
    }

    public function getTo(): string
    {
        return $this->to;
    }

    public function addGuard(callable $guard): void
    {
        $this->guards[] = $guard;
    }

    public function validateGuards(array $context): bool
    {
        foreach ($this->guards as $guard) {
            if (!$guard($context)) {
                return false;
            }
        }
        return true;
    }
}

class StateManager
{
    private $connection;

    public function getCurrentState(string $processId): string
    {
        return $this->connection->table('workflow_states')
            ->where('process_id', $processId)
            ->value('current_state');
    }

    public function initializeState(string $processId, string $initialState): void
    {
        $this->connection->table('workflow_states')->insert([
            'process_id' => $processId,
            'current_state' => $initialState,
            'created_at' => now()
        ]);
    }

    public function transition(string $processId, string $from, string $to): void
    {
        $this->connection->table('workflow_states')
            ->where('process_id', $processId)
            ->where('current_state', $from)
            ->update([
                'current_state' => $to,
                'updated_at' => now()
            ]);

        $this->connection->table('workflow_transitions')->insert([
            'process_id' => $processId,
            'from_state' => $from,
            'to_state' => $to,
            'transitioned_at' => now()
        ]);
    }
}

class TransitionValidator
{
    public function canTransition(Workflow $workflow, string $currentState, string $transition): bool
    {
        $availableTransitions = $workflow->getAvailableTransitions($currentState);
        
        foreach ($availableTransitions as $t) {
            if ($t->getName() === $transition) {
                return true;
            }
        }
        
        return false;
    }
}

class Action
{
    private string $name;
    private callable $handler;
    private array $requirements = [];

    public function __construct(string $name, callable $handler)
    {
        $this->name = $name;
        $this->handler = $handler;
    }

    public function execute(array $context = []): void
    {
        $this->validateRequirements($context);
        ($this->handler)($context);
    }

    public function addRequirement(callable $requirement): void
    {
        $this->requirements[] = $requirement;
    }

    private function validateRequirements(array $context): void
    {
        foreach ($this->requirements as $requirement) {
            if (!$requirement($context)) {
                throw new WorkflowException("Action requirement not met");
            }
        }
    }
}

class WorkflowException extends \Exception {}
