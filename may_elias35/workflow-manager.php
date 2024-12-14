<?php

namespace App\Core\Workflow;

use App\Core\Security\SecurityManager;
use App\Core\Monitoring\SystemMonitor;
use App\Core\Task\TaskManager;
use App\Core\Exceptions\WorkflowException;

class WorkflowManager implements WorkflowInterface
{
    private SecurityManager $security;
    private SystemMonitor $monitor;
    private TaskManager $taskManager;
    private array $config;
    private array $activeWorkflows = [];

    public function __construct(
        SecurityManager $security,
        SystemMonitor $monitor,
        TaskManager $taskManager,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->taskManager = $taskManager;
        $this->config = $config;
    }

    public function createWorkflow(array $definition): Workflow
    {
        $monitoringId = $this->monitor->startOperation('workflow_create');
        
        try {
            $this->validateWorkflowDefinition($definition);
            
            DB::beginTransaction();
            
            $workflow = $this->prepareWorkflow($definition);
            $workflow->save();
            
            $this->createWorkflowStages($workflow, $definition['stages']);
            $this->createWorkflowTransitions($workflow, $definition['transitions']);
            
            DB::commit();
            
            $this->monitor->recordSuccess($monitoringId);
            return $workflow;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->monitor->recordFailure($monitoringId, $e);
            throw new WorkflowException('Workflow creation failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function executeWorkflow(int $workflowId, array $context = []): WorkflowExecution
    {
        $monitoringId = $this->monitor->startOperation('workflow_execution');
        
        try {
            $workflow = $this->getWorkflow($workflowId);
            
            $this->validateWorkflowExecution($workflow, $context);
            $execution = $this->initializeExecution($workflow, $context);
            
            $this->processWorkflowStages($execution);
            
            $this->monitor->recordSuccess($monitoringId);
            return $execution;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new WorkflowException('Workflow execution failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function transitionWorkflow(int $executionId, string $transition): bool
    {
        $monitoringId = $this->monitor->startOperation('workflow_transition');
        
        try {
            $execution = $this->getExecution($executionId);
            
            $this->validateTransition($execution, $transition);
            $this->executeTransition($execution, $transition);
            
            $this->monitor->recordSuccess($monitoringId);
            return true;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new WorkflowException('Workflow transition failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function validateWorkflowDefinition(array $definition): void
    {
        if (!isset($definition['stages']) || empty($definition['stages'])) {
            throw new WorkflowException('Workflow must have at least one stage');
        }

        if (!isset($definition['transitions']) || empty($definition['transitions'])) {
            throw new WorkflowException('Workflow must have transitions defined');
        }

        $this->validateStages($definition['stages']);
        $this->validateTransitions($definition['transitions']);
        $this->validateWorkflowStructure($definition);
    }

    private function prepareWorkflow(array $definition): Workflow
    {
        $workflow = new Workflow();
        $workflow->fill([
            'name' => $definition['name'],
            'description' => $definition['description'] ?? '',
            'type' => $definition['type'],
            'version' => $definition['version'] ?? '1.0',
            'status' => 'active',
            'created_by' => auth()->id(),
            'created_at' => now()
        ]);
        
        return $workflow;
    }

    private function createWorkflowStages(Workflow $workflow, array $stages): void
    {
        