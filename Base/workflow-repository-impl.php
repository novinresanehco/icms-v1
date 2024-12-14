<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\WorkflowRepositoryInterface;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Exceptions\WorkflowException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class WorkflowRepository implements WorkflowRepositoryInterface
{
    /**
     * Cache TTL in seconds
     */
    protected const CACHE_TTL = 3600;

    /**
     * Available workflow types
     */
    protected const AVAILABLE_TYPES = [
        'content_approval',
        'document_review',
        'user_onboarding',
        'content_publishing'
    ];

    /**
     * Create a new workflow
     *
     * @param string $type
     * @param array $config
     * @return Workflow
     * @throws WorkflowException
     */
    public function createWorkflow(string $type, array $config): Workflow
    {
        if (!$this->validateConfig($config, $type)) {
            throw new WorkflowException("Invalid workflow configuration for type: {$type}");
        }

        try {
            return DB::transaction(function () use ($type, $config) {
                $workflow = Workflow::create([
                    'type' => $type,
                    'name' => $config['name'] ?? "New {$type} Workflow",
                    'config' => json_encode($config),
                    'status' => 'active'
                ]);

                // Create initial steps if provided
                if (isset($config['steps'])) {
                    foreach ($config['steps'] as $stepData) {
                        $this->addWorkflowStep($workflow, $stepData);
                    }
                }

                $this->clearWorkflowCache($type);
                return $workflow;
            });
        } catch (QueryException $e) {
            throw new WorkflowException("Failed to create workflow: " . $e->getMessage());
        }
    }

    /**
     * Add a step to an existing workflow
     *
     * @param Workflow $workflow
     * @param array $stepData
     * @return WorkflowStep
     */
    public function addWorkflowStep(Workflow $workflow, array $stepData): WorkflowStep
    {
        $position = $workflow->steps()->count() + 1;
        
        $step = $workflow->steps()->create([
            'name' => $stepData['name'],
            'type' => $stepData['type'],
            'config' => json_encode($stepData['config'] ?? []),
            'position' => $position,
            'required' => $stepData['required'] ?? true
        ]);

        $this->clearWorkflowCache($workflow->type);
        
        return $step;
    }

    /**
     * Get workflow by ID with caching
     *
     * @param int $id
     * @return Workflow|null
     */
    public function getWorkflowById(int $id): ?Workflow
    {
        return Cache::remember("workflow:{$id}", self::CACHE_TTL, function () use ($id) {
            return Workflow::with('steps')
                ->find($id);
        });
    }

    /**
     * Get workflows by type with caching
     *
     * @param string $type
     * @return Collection
     */
    public function getWorkflowsByType(string $type): Collection
    {
        return Cache::remember("workflows:type:{$type}", self::CACHE_TTL, function () use ($type) {
            return Workflow::where('type', $type)
                ->with('steps')
                ->orderBy('created_at', 'desc')
                ->get();
        });
    }

    /**
     * Update workflow configuration
     *
     * @param Workflow $workflow
     * @param array $config
     * @return Workflow
     * @throws WorkflowException
     */
    public function updateWorkflow(Workflow $workflow, array $config): Workflow
    {
        if (!$this->validateConfig($config, $workflow->type)) {
            throw new WorkflowException("Invalid workflow configuration");
        }

        try {
            DB::transaction(function () use ($workflow, $config) {
                $workflow->update([
                    'name' => $config['name'] ?? $workflow->name,
                    'config' => json_encode($config),
                    'status' => $config['status'] ?? $workflow->status
                ]);
            });

            $this->clearWorkflowCache($workflow->type);
            return $workflow->fresh(['steps']);
        } catch (QueryException $e) {
            throw new WorkflowException("Failed to update workflow: " . $e->getMessage());
        }
    }

    /**
     * Clear workflow cache by type
     *
     * @param string $type
     * @return void
     */
    protected function clearWorkflowCache(string $type): void
    {
        Cache::tags(['workflows', "workflow:type:{$type}"])->flush();
    }

    /**
     * Validate workflow configuration
     *
     * @param array $config
     * @param string $type
     * @return bool
     */
    public function validateConfig(array $config, string $type): bool
    {
        // Basic validation
        if (!in_array($type, self::AVAILABLE_TYPES)) {
            return false;
        }

        // Validate required fields
        $required = ['name'];
        foreach ($required as $field) {
            if (!isset($config[$field])) {
                return false;
            }
        }

        // Validate steps if provided
        if (isset($config['steps'])) {
            foreach ($config['steps'] as $step) {
                if (!isset($step['name'], $step['type'])) {
                    return false;
                }
            }
        }

        return true;
    }

    // Additional method implementations...
}
