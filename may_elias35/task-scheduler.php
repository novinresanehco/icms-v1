<?php

namespace App\Core\Scheduler;

class TaskScheduler
{
    private TaskQueue $queue;
    private TaskRunner $runner;
    private StateManager $stateManager;
    private RetryManager $retryManager;
    private SchedulerLogger $logger;

    public function __construct(
        TaskQueue $queue,
        TaskRunner $runner,
        StateManager $stateManager,
        RetryManager $retryManager,
        SchedulerLogger $logger
    ) {
        $this->queue = $queue;
        $this->runner = $runner;
        $this->stateManager = $stateManager;
        $this->retryManager = $retryManager;
        $this->logger = $logger;
    }

    public function schedule(Task $task, ScheduleConfig $config): string
    {
        $taskId = $this->generateTaskId();
        
        $this->validateTask($task);
        $this->stateManager->initializeTask($taskId, $task, $config);
        $this->queue->enqueue($taskId, $task, $config->getScheduledTime());
        
        $this->logger->logTaskScheduled($taskId, $task, $config);
        
        return $taskId;
    }

    public function cancelTask(string $taskId): void
    {
        $this->validateTaskExists($taskId);
        
        $this->queue->remove($taskId);
        $this->stateManager->markTaskCancelled($taskId);
        
        $this->logger->logTaskCancelled($taskId);
    }

    public function executeTask(string $taskId): ExecutionResult
    {
        $this->validateTaskExists($taskId);
        $task = $this->stateManager->getTask($taskId);
        
        try {
            $this->stateManager->markTaskStarted($taskId);
            $result = $this->runner->execute($task);
            
            if ($result->isSuccess()) {
                $this->handleTaskSuccess($taskId, $result);
            } else {
                $this->handleTaskFailure($taskId, $result);
            }
            
            return $result;
        } catch (\Exception $e) {
            return $this->handleTaskError($taskId, $e);
        }
    }

    protected function handleTaskSuccess(string $taskId, ExecutionResult $result): void
    {
        $this->stateManager->markTaskCompleted($taskId, $result);
        $this->logger->logTaskCompleted($taskId, $result);
        
        if ($this->shouldReschedule($taskId)) {
            $this->rescheduleTask($taskId);
        }
    }

    protected function handleTaskFailure(string $taskId, ExecutionResult $result): void
    {
        if ($this->retryManager->shouldRetry($taskId)) {
            $this->retryTask($taskId);
        } else {
            $this->stateManager->markTaskFailed($taskId, $result);
            $this->logger->logTaskFailed($taskId, $result);
        }
    }

    protected function handleTaskError(string $taskId, \Exception $e): ExecutionResult
    {
        $result = new ExecutionResult(false, ['error' => $e->getMessage()]);
        $this->stateManager->markTaskError($taskId, $e);
        $this->logger->logTaskError($taskId, $e);
        
        if ($this->retryManager->shouldRetry($taskId)) {
            $this->retryTask($taskId);
        }
        
        return $result;
    }

    protected function retryTask(string $taskId): void
    {
        $delay = $this->retryManager->getNextRetryDelay($taskId);
        $this->queue->enqueue($taskId, $this->stateManager->getTask($taskId), time() + $delay);
        $this->stateManager->markTaskRetrying($taskId);
        $this->logger->logTaskRetry($taskId, $delay);
    }

    protected function rescheduleTask(string $taskId): void
    {
        $config = $this->stateManager->getConfig($taskId);
        $nextRunTime = $config->getNextRunTime();
        
        if ($nextRunTime) {
            $this->queue->enqueue($taskId, $this->stateManager->getTask($taskId), $nextRunTime);
            $this->stateManager->updateNextRunTime($taskId, $nextRunTime);
            $this->logger->logTaskRescheduled($taskId, $nextRunTime);
        }
    }

    protected function shouldReschedule(string $taskId): bool
    {
        $config = $this->stateManager->getConfig($taskId);
        return $config->isRecurring() && $config->getNextRunTime() !== null;
    }

    protected function validateTask(Task $task): void
    {
        if (!$task->isValid()) {
            throw new InvalidTaskException('Task validation failed');
        }
    }

    protected function validateTaskExists(string $taskId): void
    {
        if (!$this->stateManager->taskExists($taskId)) {
            throw new TaskNotFoundException("Task $taskId not found");
        }
    }

    protected function generateTaskId(): string
    {
        return uniqid('task_', true);
    }

    public function getTaskStatus(string $taskId): array
    {
        $this->validateTaskExists($taskId);
        
        return [
            'state' => $this->stateManager->getTaskState($taskId),
            'next_run' => $this->stateManager->getNextRunTime($taskId),
            'retry_count' => $this->retryManager->getRetryCount($taskId),
            'last_result' => $this->stateManager->getLastResult($taskId)
        ];
    }

    public function getPendingTasks(): array
    {
        return $this->queue->getPendingTasks();
    }

    public function getFailedTasks(): array
    {
        return $this->stateManager->getFailedTasks();
    }
}
