<?php

namespace App\Repositories;

use App\Models\Workflow;
use App\Repositories\Contracts\WorkflowRepositoryInterface;
use Illuminate\Support\Collection;

class WorkflowRepository extends BaseRepository implements WorkflowRepositoryInterface
{
    protected array $searchableFields = ['name', 'description'];
    protected array $filterableFields = ['type', 'status'];
    protected array $relationships = ['steps', 'transitions'];

    public function getActiveWorkflows(): Collection
    {
        return Cache::remember(
            $this->getCacheKey('active'),
            $this->cacheTTL,
            fn() => $this->model->with($this->relationships)
                ->where('status', 'active')
                ->get()
        );
    }

    public function findByType(string $type): Collection
    {
        return Cache::remember(
            $this->getCacheKey("type.{$type}"),
            $this->cacheTTL,
            fn() => $this->model->with($this->relationships)
                ->where('type', $type)
                ->get()
        );
    }

    public function createWithSteps(array $data, array $steps): Workflow
    {
        try {
            DB::beginTransaction();
            
            $workflow = $this->create($data);
            
            foreach ($steps as $order => $step) {
                $workflow->steps()->create([
                    'name' => $step['name'],
                    'type' => $step['type'],
                    'config' => $step['config'] ?? [],
                    'order' => $order
                ]);
            }
            
            DB::commit();
            $this->clearModelCache();
            return $workflow->load('steps');
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RepositoryException("Failed to create workflow: {$e->getMessage()}");
        }
    }

    public function updateTransitions(int $workflowId, array $transitions): Workflow
    {
        try {
            DB::beginTransaction();
            
            $workflow = $this->findOrFail($workflowId);
            $workflow->transitions()->delete();
            
            foreach ($transitions as $transition) {
                $workflow->transitions()->create([
                    'from_step' => $transition['from'],
                    'to_step' => $transition['to'],
                    'conditions' => $transition['conditions'] ?? []
                ]);
            }
            
            DB::commit();
            $this->clearModelCache();
            return $workflow->load('transitions');
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RepositoryException("Failed to update transitions: {$e->getMessage()}");
        }
    }
}
