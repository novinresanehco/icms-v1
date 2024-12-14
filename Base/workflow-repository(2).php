<?php

namespace App\Core\Repositories;

use App\Core\Models\{Workflow, WorkflowState, WorkflowTransition};
use App\Core\Contracts\Workflowable;
use App\Core\Events\{StateChanged, TransitionExecuted};
use App\Core\Exceptions\WorkflowException;
use Illuminate\Database\Eloquent\{Model, Collection};
use Illuminate\Support\Facades\{DB, Event};

class WorkflowRepository extends Repository
{
    protected array $with = ['states', 'transitions'];

    public function findByType(string $type): ?Model
    {
        return $this->remember(fn() =>
            $this->query()
                ->where('type', $type)
                ->first()
        );
    }

    public function getCurrentState(Workflowable $model): ?WorkflowState
    {
        return $model->workflowStates()
            ->orderByDesc('created_at')
            ->first();
    }

    public function getAvailableTransitions(Workflowable $model): Collection
    {
        $currentState = $this->getCurrentState($model);
        
        if (!$currentState) {
            return collect();
        }

        return WorkflowTransition::where('from_state_id', $currentState->id)
            ->with('toState')
            ->get();
    }

    public function transition(
        Workflowable $model,
        WorkflowTransition $transition,
        array $data = []
    ): bool {
        return DB::transaction(function() use ($model, $transition, $data) {
            $currentState = $this->getCurrentState($model);

            if (!$currentState || $currentState->id !== $transition->from_state_id) {
                throw new WorkflowException('Invalid transition for current state');
            }

            $model->workflowStates()->create([
                'state_id' => $transition->to_state_id,
                'data' => $data,
                'triggered_by' => auth()->id()
            ]);

            Event::dispatch(new StateChanged($model, $currentState, $transition->toState));
            Event::dispatch(new TransitionExecuted($model, $transition, $data));

            return true;
        });
    }
}

class WorkflowStateRepository extends Repository
{
    public function createInitialState(Workflow $workflow): Model
    {
        return $this->create([
            'workflow_id' => $workflow->id,
            'name' => 'initial',
            'is_initial' => true,
            'metadata' => ['system' => true]
        ]);
    }

    public function getByWorkflow(Workflow $workflow): Collection
    {
        return $this->remember(fn() =>
            $this->query()
                ->where('workflow_id', $workflow->id)
                ->orderBy('sort_order')
                ->get()
        );
    }

    public function reorder(array $states): bool
    {
        return DB::transaction(function() use ($states) {
            foreach ($states as $index => $state) {
                $this->update($state['id'], [
                    'sort_order' => $index + 1
                ]);
            }
            return true;
        });
    }
}

class WorkflowTransitionRepository extends Repository
{
    protected array $with = ['fromState', 'toState', 'conditions'];

    public function createTransition(
        WorkflowState $fromState,
        WorkflowState $toState,
        array $attributes = []
    ): Model {
        return $this->create(array_merge([
            'from_state_id' => $fromState->id,
            'to_state_id' => $toState->id,
            'workflow_id' => $fromState->workflow_id
        ], $attributes));
    }

    public function getByWorkflow(Workflow $workflow): Collection
    {
        return $this->remember(fn() =>
            $this->query()
                ->where('workflow_id', $workflow->id)
                ->get()
        );
    }
}

class WorkflowHistoryRepository extends Repository
{
    public function logTransition(
        Workflowable $model,
        WorkflowTransition $transition,
        array $data = []
    ): Model {
        return $this->create([
            'workflowable_type' => get_class($model),
            'workflowable_id' => $model->getId(),
            'transition_id' => $transition->id,
            'from_state_id' => $transition->from_state_id,
            'to_state_id' => $transition->to_state_id,
            'data' => $data,
            'triggered_by' => auth()->id()
        ]);
    }

    public function getHistory(Workflowable $model): Collection
    {
        return $this->query()
            ->where('workflowable_type', get_class($model))
            ->where('workflowable_id', $model->getId())
            ->with(['transition', 'fromState', 'toState', 'triggeredBy'])
            ->orderByDesc('created_at')
            ->get();
    }
}

class WorkflowConditionRepository extends Repository
{
    public function addCondition(
        WorkflowTransition $transition,
        string $type,
        array $configuration = []
    ): Model {
        return $this->create([
            'transition_id' => $transition->id,
            'type' => $type,
            'configuration' => $configuration
        ]);
    }

    public function getConditions(WorkflowTransition $transition): Collection
    {
        return $this->query()
            ->where('transition_id', $transition->id)
            ->orderBy('sort_order')
            ->get();
    }

    public function reorderConditions(array $conditions): bool
    {
        return DB::transaction(function() use ($conditions) {
            foreach ($conditions as $index => $condition) {
                $this->update($condition['id'], [
                    'sort_order' => $index + 1
                ]);
            }
            return true;
        });
    }
}
