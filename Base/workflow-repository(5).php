<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\WorkflowRepositoryInterface;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Models\WorkflowTransition;
use Illuminate\Support\Collection;
use App\Events\WorkflowStepCompleted;
use App\Events\WorkflowCompleted;

class WorkflowRepository extends BaseRepository implements WorkflowRepositoryInterface
{
    public function __construct(Workflow $model)
    {
        parent::__construct($model);
    }

    public function createWorkflow(string $type, array $data): Workflow
    {
        $workflow = $this->create([
            'type' => $type,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'status' => 'active',
            'steps' => $this->prepareWorkflowSteps($data['steps']),
            'config' => [
                'allow_concurrent' => $data['allow_concurrent'] ?? false,
                'require_comments' => $data['require_comments'] ?? true,
                'auto_assign' => $data['auto_assign'] ?? false,
                'notify_on_transition' => $data['notify_on_transition'] ?? true,
                'timeout_minutes' => $data['timeout_minutes'] ?? null
            ]
        ]);

        $this->createWorkflowSteps($workflow, $data['steps']);

        return $workflow;
    }

    public function startWorkflow(int $workflowId, string $entityType, int $entityId): ?WorkflowStep
    {
        $workflow = $this->find($workflowId);
        if (!$workflow || $workflow->status !== 'active') {
            return null;
        }

        $firstStep = $workflow->steps()->orderBy('order')->first();
        if (!$firstStep) {
            return null;
        }

        return $this->createWorkflowStep([
            'workflow_id' => $workflowId,
            'step_id' => $firstStep->id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'status' => 'pending',
            'assigned_to' => $this->determineAssignee($firstStep)
        ]);
    }

    public function completeStep(int $stepId, array $data): bool
    {
        $step = WorkflowStep::find($stepId);
        if (!$step || $step->status !== 'pending') {
            return false;
        }

        $completed = $step->update([
            'status' => 'completed',
            'completed_by' => auth()->id(),
            'completed_at' => now(),
            'comments' => $data['comments'] ?? null,
            'metadata' => array_merge($step->metadata ?? [], [
                'completion_data' => $data,
                'browser' => request()->userAgent(),
                'ip' => request()->ip()
            ])
        ]);

        if ($completed) {
            event(new WorkflowStepCompleted($step));
            
            $nextStep = $this->moveToNextStep($step);
            if (!$nextStep) {
                $this->completeWorkflow($step->workflow_id);
            }
        }

        return $completed;
    }

    public function moveToNextStep(WorkflowStep $currentStep): ?WorkflowStep
    {
        $nextStep = $currentStep->workflow->steps()
            ->where('order', '>', $currentStep->order)
            ->orderBy('order')
            ->first();

        if (!$nextStep) {
            return null;
        }

        return $this->createWorkflowStep([
            'workflow_id' => $currentStep->workflow_id,
            'step_id' => $nextStep->id,
            'entity_type' => $currentStep->entity_type,
            'entity_id' => $currentStep->entity_id,
            'status' => 'pending',
            'assigned_to' => $this->determineAssignee($nextStep)
        ]);
    }

    public function getActiveWorkflows(): Collection
    {
        return $this->model
            ->where('status', 'active')
            ->with(['currentStep', 'steps'])
            ->get();
    }

    public function getEntityWorkflows(string $entityType, int $entityId): Collection
    {
        return $this->model
            ->whereHas('steps', function ($query) use ($entityType, $entityId) {
                $query->where('entity_type', $entityType)
                    ->where('entity_id', $entityId);
            })
            ->with(['steps', 'currentStep'])
            ->get();
    }

    protected function prepareWorkflowSteps(array $steps): array
    {
        $preparedSteps = [];
        foreach ($steps as $index => $step) {
            $preparedSteps[] = [
                'name' => $step['name'],
                'description' => $step['description'] ?? null,
                'order' => $index + 1,
                'assignee_type' => $step['assignee_type'] ?? 'user',
                'assignee_id' => $step['assignee_id'] ?? null,
                'timeout_minutes' => $step['timeout_minutes'] ?? null,
                'required_fields' => $step['required_fields'] ?? [],
                'validation_rules' => $step['validation_rules'] ?? []
            ];
        }
        return $preparedSteps;
    }

    protected function createWorkflowSteps(Workflow $workflow, array $steps): void
    {
        foreach ($steps as $index => $step) {
            WorkflowStep::create([
                'workflow_id' => $workflow->id,
                'name' => $step['name'],
                'description' => $step['description'] ?? null,
                'order' => $index + 1,
                'assignee_type' => $step['assignee_type'] ?? 'user',
                'assignee_id' => $step['assignee_id'] ?? null,
                'timeout_minutes' => $step['timeout_minutes'] ?? null,
                'required_fields' => $step['required_fields'] ?? [],
                'validation_rules' => $step['validation_rules'] ?? []
            ]);
        }
    }

    protected function determineAssignee(WorkflowStep $step): ?int
    {
        // Implement assignee determination logic based on step configuration
        return $step->assignee_id;
    }

    protected function completeWorkflow(int $workflowId): void
    {
        $workflow = $this->find($workflowId);
        if ($workflow) {
            $workflow->update(['status' => 'completed']);
            event(new WorkflowCompleted($workflow));
        }
    }
}
