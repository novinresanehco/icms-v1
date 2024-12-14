<?php

namespace App\Core\Repositories;

use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Core\Services\Cache\CacheService;
use Illuminate\Support\Collection;

class WorkflowRepository extends AdvancedRepository
{
    protected $model = Workflow::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function getActiveWorkflows(): Collection
    {
        return $this->executeQuery(function() {
            return $this->cache->remember('workflows.active', function() {
                return $this->model
                    ->where('active', true)
                    ->with(['steps', 'transitions'])
                    ->get();
            });
        });
    }

    public function createWorkflow(array $data, array $steps): Workflow
    {
        return $this->executeTransaction(function() use ($data, $steps) {
            $workflow = $this->create($data);
            
            foreach ($steps as $order => $step) {
                $workflow->steps()->create([
                    'name' => $step['name'],
                    'type' => $step['type'],
                    'config' => $step['config'] ?? [],
                    'order' => $order
                ]);
            }
            
            $this->cache->tags('workflows')->flush();
            return $workflow->fresh(['steps']);
        });
    }

    public function updateSteps(Workflow $workflow, array $steps): void
    {
        $this->executeTransaction(function() use ($workflow, $steps) {
            // Delete existing steps
            $workflow->steps()->delete();
            
            // Create new steps
            foreach ($steps as $order => $step) {
                $workflow->steps()->create([
                    'name' => $step['name'],
                    'type' => $step['type'],
                    'config' => $step['config'] ?? [],
                    'order' => $order
                ]);
            }
            
            $this->cache->tags('workflows')->flush();
        });
    }

    public function createTransition(
        Workflow $workflow, 
        WorkflowStep $fromStep, 
        WorkflowStep $toStep, 
        array $conditions = []
    ): void {
        $this->executeTransaction(function() use ($workflow, $fromStep, $toStep, $conditions) {
            $workflow->transitions()->create([
                'from_step_id' => $fromStep->id,
                'to_step_id' => $toStep->id,
                'conditions' => $conditions
            ]);
            
            $this->cache->tags('workflows')->flush();
        });
    }
}
