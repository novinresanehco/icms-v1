<?php

namespace App\Core\Repository;

use App\Models\Workflow;
use App\Core\Events\WorkflowEvents;
use App\Core\Exceptions\WorkflowRepositoryException;

class WorkflowRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Workflow::class;
    }

    /**
     * Create workflow
     */
    public function createWorkflow(string $type, array $steps, array $config = []): Workflow
    {
        try {
            $workflow = $this->create([
                'type' => $type,
                'steps' => $steps,
                'config' => $config,
                'status' => 'active',
                'created_by' => auth()->id()
            ]);

            event(new WorkflowEvents\WorkflowCreated($workflow));
            return $workflow;
        } catch (\Exception $e) {
            throw new WorkflowRepositoryException(
                "Failed to create workflow: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get active workflows by type
     */
    public function getActiveWorkflows(string $type): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("active.{$type}"),
            $this->cacheTime,
            fn() => $this->model->where('type', $type)
                               ->where('status', 'active')
                               ->get()
        );
    }

    /**
     * Get workflow state
     */
    public function getWorkflowState(int $workflowId): array
    {
        $workflow = $this->find($workflowId);
        if (!$workflow) {
            throw new WorkflowRepositoryException("Workflow not found with ID: {$workflowId}");
        }

        return [
            'current_step' => $workflow->current_step,
            'completed_steps' => $workflow->completed_steps,
            'status' => $workflow->status,
            'data' => $workflow->data
        ];
    }

    /**
     * Advance workflow
     */
    public function advanceWorkflow(int $workflowId, array $stepData): Workflow
    {
        try {
            $workflow = $this->find($workflowId);
            if (!$workflow) {
                throw new WorkflowRepositoryException("Workflow not found with ID: {$workflowId}");
            }

            // Update workflow state
            $completedSteps = $workflow->completed_steps ?? [];
            $completedSteps[] = $workflow->current_step;

            $workflow->update([
                'completed_steps' => $completedSteps,
                'current_step' => $this->getNextStep($workflow),
                'data' => array_merge($workflow->data ?? [], $stepData)
            ]);

            event(new WorkflowEvents\WorkflowAdvanced($workflow));
            return $workflow;
        } catch (\Exception $e) {
            throw new WorkflowRepositoryException(
                "Failed to advance workflow: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get workflow history
     */
    public function getWorkflowHistory(int $workflowId): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("history.{$workflowId}"),
            $this->cacheTime,
            fn() => $this->model->find($workflowId)
                               ->history()
                               ->with('user')
                               ->orderBy('created_at')
                               ->get()
        );
    }
}
