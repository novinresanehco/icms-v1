<?php

namespace App\Core\Services;

use App\Core\Repositories\{
    WorkflowRepository,
    WorkflowStateRepository,
    WorkflowTransitionRepository,
    WorkflowHistoryRepository,
    WorkflowConditionRepository
};
use App\Core\Contracts\Workflowable;
use App\Core\Events\{WorkflowCreated, StateCreated, TransitionCreated};
use App\Core\Exceptions\WorkflowException;
use Illuminate\Database\Eloquent\{Model, Collection};
use Illuminate\Support\Facades\{DB, Event, Cache};

class WorkflowService extends BaseService
{
    protected WorkflowStateRepository $stateRepository;
    protected WorkflowTransitionRepository $transitionRepository;
    protected WorkflowHistoryRepository $historyRepository;
    protected WorkflowConditionRepository $conditionRepository;

    public function __construct(
        WorkflowRepository $repository,
        WorkflowStateRepository $stateRepository,
        WorkflowTransitionRepository $transitionRepository,
        WorkflowHistoryRepository $historyRepository,
        WorkflowConditionRepository $conditionRepository
    ) {
        parent::__construct($repository);
        $this->stateRepository = $stateRepository;
        $this->transitionRepository = $transitionRepository;
        $this->historyRepository = $historyRepository;
        $this->conditionRepository = $conditionRepository;
    }

    public function createWorkflow(array $attributes): Model
    {
        try {
            DB::beginTransaction();

            $workflow = $this->repository->create($attributes);
            
            $initialState = $this->stateRepository->createInitialState($workflow);
            
            Event::dispatch(new WorkflowCreated($workflow));

            DB::commit();

            return $workflow;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new WorkflowException("Failed to create workflow: {$e->getMessage()}", 0, $e);
        }
    }

    public function addState(Model $workflow, array $attributes): Model
    {
        try {
            DB::beginTransaction();

            $state = $this->stateRepository->create(array_merge(
                $attributes,
                ['workflow_id' => $workflow->id]
            ));

            Event::dispatch(new StateCreated($state));

            DB::commit();

            return $state;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new WorkflowException("Failed to add state: {$e->getMessage()}", 0, $e);
        }
    }

    public function addTransition(
        Model $fromState,
        Model $toState,
        array $attributes = []
    ): Model {
        try {
            DB::beginTransaction();

            $transition = $this->transitionRepository->createTransition(
                $fromState,
                $toState,
                $attributes
            );

            Event::dispatch(new TransitionCreated($transition));

            DB::commit();

            return $transition;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new WorkflowException("Failed to add transition: {$e->getMessage()}", 0, $e);
        }
    }

    public function transition(
        Workflowable $model,
        Model $transition,
        array $data = []
    ): bool {
        try {
            if (!$this->canTransition($model, $transition)) {
                throw new WorkflowException('Transition conditions not met');
            }

            DB::beginTransaction();

            $transitioned = $this->repository->transition($model, $transition, $data);
            
            if ($transitioned) {
                $this->historyRepository->logTransition($model, $transition, $data);
            }

            DB::commit();

            return $transitioned;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new WorkflowException("Transition failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function getAvailableTransitions(Workflowable $model): Collection
    {
        return Cache::remember(
            "workflow_transitions:{$model->getId()}",
            3600,
            fn() => $this->repository->getAvailableTransitions($model)
        );
    }

    public function getHistory(Workflowable $model): Collection
    {
        return $this->historyRepository->getHistory($model);
    }

    protected function canTransition(
        Workflowable $model,
        Model $transition
    ): bool {
        $conditions = $this->conditionRepository->getConditions($transition);

        foreach ($conditions as $condition) {
            if (!$this->evaluateCondition($condition, $model)) {
                return false;
            }
        }

        return true;
    }

    protected function evaluateCondition(Model $condition, Workflowable $model): bool
    {
        $evaluator = app()->make($condition->type);
        return $evaluator->evaluate($condition->configuration, $model);
    }
}
