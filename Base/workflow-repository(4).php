<?php

namespace App\Core\Repositories\Contracts;

use App\Models\Workflow;
use App\Models\WorkflowStep;
use Illuminate\Support\Collection;

interface WorkflowRepositoryInterface extends RepositoryInterface 
{
    /**
     * Create a new workflow
     *
     * @param string $type Workflow type identifier
     * @param array $config Workflow configuration
     * @return Workflow
     */
    public function createWorkflow(string $type, array $config): Workflow;

    /**
     * Add a step to an existing workflow
     * 
     * @param Workflow $workflow
     * @param array $stepData
     * @return WorkflowStep
     */
    public function addWorkflowStep(Workflow $workflow, array $stepData): WorkflowStep;

    /**
     * Get workflow by ID
     *
     * @param int $id
     * @return Workflow|null
     */
    public function getWorkflowById(int $id): ?Workflow;

    /**
     * Get workflows by type
     *
     * @param string $type
     * @return Collection
     */
    public function getWorkflowsByType(string $type): Collection;

    /**
     * Update workflow configuration
     *
     * @param Workflow $workflow
     * @param array $config
     * @return Workflow
     */
    public function updateWorkflow(Workflow $workflow, array $config): Workflow;

    /**
     * Update workflow step
     *
     * @param WorkflowStep $step
     * @param array $data
     * @return WorkflowStep
     */
    public function updateWorkflowStep(WorkflowStep $step, array $data): WorkflowStep;

    /**
     * Delete workflow and associated steps
     *
     * @param Workflow $workflow
     * @return bool
     */
    public function deleteWorkflow(Workflow $workflow): bool;

    /**
     * Get workflow steps in order
     *
     * @param Workflow $workflow
     * @return Collection
     */
    public function getOrderedSteps(Workflow $workflow): Collection;

    /**
     * Reorder workflow steps
     *
     * @param Workflow $workflow
     * @param array $stepOrder Array of step IDs in desired order
     * @return bool
     */
    public function reorderSteps(Workflow $workflow, array $stepOrder): bool;

    /**
     * Clone an existing workflow
     *
     * @param Workflow $workflow
     * @param string|null $newName
     * @return Workflow
     */
    public function cloneWorkflow(Workflow $workflow, ?string $newName = null): Workflow;

    /**
     * Get available workflow types
     *
     * @return array
     */
    public function getAvailableTypes(): array;

    /**
     * Validate workflow configuration
     *
     * @param array $config
     * @param string $type
     * @return bool
     */
    public function validateConfig(array $config, string $type): bool;
}
