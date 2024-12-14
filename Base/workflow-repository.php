<?php

namespace App\Repositories;

use App\Models\Workflow;
use App\Repositories\Contracts\WorkflowRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class WorkflowRepository extends BaseRepository implements WorkflowRepositoryInterface
{
    protected array $searchableFields = ['name', 'description', 'type'];
    protected array $filterableFields = ['status', 'entity_type'];

    public function createWorkflow(array $data): Workflow
    {
        $workflow = $this->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'],
            'entity_type' => $data['entity_type'],
            'steps' => $data['steps'],
            'settings' => $data['settings'] ?? [],
            'status' => 'draft',
            'created_by' => auth()->id()
        ]);

        Cache::tags(['workflows'])->flush();
        return $workflow;
    }

    public function getActiveWorkflows(string $entityType = null): Collection
    {
        $query = $this->model->where('status', 'active');
        
        if ($entityType) {
            $query->where('entity_type', $entityType);
        }

        return $query->orderBy('priority')->get();
    }

    public function updateSteps(int $id, array $steps): bool
    {
        try {
            $result = $this->update($id, [
                'steps' => $steps,
                'last_modified_at' => now(),
                'last_modified_by' => auth()->id()
            ]);

            Cache::tags(['workflows'])->flush();
            return $result;
        } catch (\Exception $e) {
            \Log::error('Error updating workflow steps: ' . $e->getMessage());
            return false;
        }
    }

    public function getWorkflowInstances(int $workflowId): Collection
    {
        return $this->model->find($workflowId)
            ->instances()
            ->with(['entity', 'currentStep'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getWorkflowMetrics(int $workflowId): array
    {
        $workflow = $this->findById($workflowId);
        
        return [
            'total_instances' => $workflow->instances()->count(),
            'completed_instances' => $workflow->instances()->where('status', 'completed')->count(),
            'average_completion_time' => $this->calculateAverageCompletionTime($workflow),
            'step_metrics' => $this->calculateStepMetrics($workflow),
            'bottlenecks' => $this->identifyBottlenecks($workflow)
        ];
    }
}
