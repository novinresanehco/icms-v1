<?php

namespace App\Core\Task;

use App\Core\Security\SecurityManager;
use App\Core\Monitoring\SystemMonitor;
use App\Core\Storage\StorageManager;
use App\Core\Exceptions\TaskException;

class TaskManager implements TaskInterface
{
    private SecurityManager $security;
    private SystemMonitor $monitor;
    private StorageManager $storage;
    private array $config;
    private array $activeTasks = [];

    public function __construct(
        SecurityManager $security,
        SystemMonitor $monitor,
        StorageManager $storage,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->storage = $storage;
        $this->config = $config;
    }

    public function createTask(array $data): Task
    {
        $monitoringId = $this->monitor->startOperation('task_create');
        
        try {
            $this->validateTaskData($data);
            
            DB::beginTransaction();
            
            $task = $this->prepareTask($data);
            $task->save();
            
            $this->assignTaskResources($task);
            $this->createTaskHistory($task);
            
            DB::commit();
            
            $this->monitor->recordSuccess($monitoringId);
            return $task;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->monitor->recordFailure($monitoringId, $e);
            throw new TaskException('Task creation failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function executeTask(int $taskId): TaskResult
    {
        $monitoringId = $this->monitor->startOperation('task_execution');
        
        try {
            $task = $this->getTask($taskId);
            
            $this->validateTaskExecution($task);
            $this->prepareTaskExecution($task);
            
            $result = $this->processTask($task);
            
            $this->updateTaskStatus($task, $result);
            $this->monitor->recordSuccess($monitoringId);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new TaskException('Task execution failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function scheduleTask(Task $task, Schedule $schedule): bool
    {
        $monitoringId = $this->monitor->startOperation('task_scheduling');
        
        try {
            $this->validateTaskScheduling($task, $schedule);
            
            DB::beginTransaction();
            
            $this->createTaskSchedule($task, $schedule);
            $this->updateTaskState($task, 'scheduled');
            
            DB::commit();
            
            $this->monitor->recordSuccess($monitoringId);
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->monitor->recordFailure($monitoringId, $e);
            throw new TaskException('Task scheduling failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function validateTaskData(array $data): void
    {
        if (!isset($data['type']) || !$this->isValidTaskType($data['type'])) {
            throw new TaskException('Invalid task type');
        }

        if (!isset($data['priority']) || !$this->isValidPriority($data['priority'])) {
            throw new TaskException('Invalid task priority');
        }

        if (!$this->validateTaskParameters($data['parameters'] ?? [])) {
            throw new TaskException('Invalid task parameters');
        }
    }

    private function prepareTask(array $data): Task
    {
        $task = new Task();
        $task->fill([
            'type' => $data['type'],
            'priority' => $data['priority'],
            'parameters' => $this->encodeParameters($data['parameters'] ?? []),
            'status' => 'pending',
            'created_by' => auth()->id(),
            'created_at' => now()
        ]);
        
        return $task;
    }

    private function assignTaskResources(Task $task): void
    {
        $resources = $this->calculateRequiredResources($task);
        
        foreach ($resources as $resource => $amount) {
            if (!$this->allocateResource($resource, $amount)) {
                throw new TaskException("Failed to allocate resource: {$resource}");
            }
        }
    }

    private function createTaskHistory(Task $task): void
    {
        TaskHistory::create([
            'task_id' => $task->id,
            'action' => 'created',
            'data' => $task->toArray(),
            'performed_by' => auth()->id(),
            'performed_at' => now()
        ]);
    }

    private function validateTaskExecution(Task $task): void
    {
        if (!$this->security->validateTaskExecution($task)) {
            throw new TaskException('Task execution not permitted');
        }

        if (!$this->validateTaskState($task)) {
            throw new TaskException('Invalid task state for execution');
        }

        if (!$this->validateTaskResources($task)) {
            throw new TaskException('Insufficient resources for task execution');
        }
    }

    private function prepareTaskExecution(Task $task): void
    {
        $this->lockTaskResources($task);
        $this->updateTaskState($task, 'executing');
        $this->initializeExecutionContext($task);
    }

    private function processTask(Task $task): TaskResult
    {
        $processor = $this->getTaskProcessor($task->type);
        return $processor->process($task);
    }

    private function updateTaskStatus(Task $task, TaskResult $result): void
    {
        $task->status = $result->isSuccess() ? 'completed' : 'failed';
        $task->result = $result->getData();
        $task->completed_at = now();
        $task->save();
        
        $this->createTaskHistory($task);
        $this->releaseTaskResources($task);
    }

    private function validateTaskScheduling(Task $task, Schedule $schedule): void
    {
        if (!$this->isValidSchedule($schedule)) {
            throw new TaskException('Invalid schedule configuration');
        }

        if (!$this->validateSchedulingConstraints($task, $schedule)) {
            throw new TaskException('Schedule constraints validation failed');
        }
    }

    private function createTaskSchedule(Task $task, Schedule $schedule): void
    {
        TaskSchedule::create([
            'task_id' => $task->id,
            'schedule_type' => $schedule->getType(),
            'schedule_data' => $schedule->getData(),
            'next_execution' => $schedule->getNextExecution(),
            'created_at' => now()
        ]);
    }

    private function isValidTaskType(string $type): bool
    {
        return in_array($type, $this->config['allowed_task_types']);
    }

    private function isValidPriority(string $priority): bool
    {
        return in_array($priority, $this->config['priority_levels']);
    }

    private function validateTaskParameters(array $parameters): bool
    {
        foreach ($parameters as $key => $value) {
            if (!$this->isValidParameter($key, $value)) {
                return false;
            }
        }
        return true;
    }

    private function encodeParameters(array $parameters): string
    {
        return json_encode($parameters);
    }

    private function calculateRequiredResources(Task $task): array
    {
        $baseResources = $this->config['resource_requirements'][$task->type] ?? [];
        $priorityMultiplier = $this->getPriorityMultiplier($task->priority);
        
        return array_map(function($amount) use ($priorityMultiplier) {
            return ceil($amount * $priorityMultiplier);
        }, $baseResources);
    }

    private function allocateResource(string $resource, int $amount): bool
    {
        return ResourceManager::allocate($resource, $amount);
    }

    private function validateTaskState(Task $task): bool
    {
        return in_array($task->status, ['pending', 'scheduled']);
    }

    private function validateTaskResources(Task $task): bool
    {
        $required = $this->calculateRequiredResources($task);
        return ResourceManager::checkAvailability($required);
    }

    private function lockTaskResources(Task $task): void
    {
        $resources = $this->calculateRequiredResources($task);
        ResourceManager::lock($resources);
    }

    private function initializeExecutionContext(Task $task): void
    {
        $this->activeTasks[$task->id] = [
            'started_at' => microtime(true),
            'resources' => $this->calculateRequiredResources($task),
            'context' => $this->createExecutionContext($task)
        ];
    }

    private function getTaskProcessor(string $type): TaskProcessorInterface
    {
        $processorClass = $this->config['task_processors'][$type] ?? null;
        
        if (!$processorClass || !class_exists($processorClass)) {
            throw new TaskException("Processor not found for task type: {$type}");
        }
        
        return new $processorClass();
    }

    private function releaseTaskResources(Task $task): void
    {
        if (isset($this->activeTasks[$task->id])) {
            ResourceManager::release($this->activeTasks[$task->id]['resources']);
            unset($this->activeTasks[$task->id]);
        }
    }

    private function isValidSchedule(Schedule $schedule): bool
    {
        return $schedule->validate();
    }

    private function validateSchedulingConstraints(Task $task, Schedule $schedule): bool
    {
        return $this->validateTimeConstraints($schedule) &&
               $this->validateResourceConstraints($task, $schedule) &&
               $this->validateDependencyConstraints($task, $schedule);
    }

    private function validateTimeConstraints(Schedule $schedule): bool
    {
        $nextExecution = $schedule->getNextExecution();
        return $nextExecution > now() &&
               $nextExecution < now()->addDays($this->config['max_schedule_days']);
    }

    private function validateResourceConstraints(Task $task, Schedule $schedule): bool
    {
        $requiredResources = $this->calculateRequiredResources($task);
        return ResourceManager::checkFutureAvailability($requiredResources, $schedule);
    }

    private function validateDependencyConstraints(Task $task, Schedule $schedule): bool
    {
        return TaskDependencyManager::validateSchedule($task, $schedule);
    }
}
