<?php

namespace App\Core\TaskExecution;

class TaskExecutionSystem implements TaskExecutionInterface
{
    private TaskScheduler $scheduler;
    private ExecutionEngine $engine;
    private TaskValidator $validator;
    private StateTracker $tracker;
    private EmergencyHandler $emergency;

    public function __construct(
        TaskScheduler $scheduler,
        ExecutionEngine $engine,
        TaskValidator $validator,
        StateTracker $tracker,
        EmergencyHandler $emergency
    ) {
        $this->scheduler = $scheduler;
        $this->engine = $engine;
        $this->validator = $validator;
        $this->tracker = $tracker;
        $this->emergency = $emergency;
    }

    public function executeTask(CriticalTask $task): ExecutionResult
    {
        $executionId = $this->tracker->startExecution();
        DB::beginTransaction();

        try {
            // Validate task
            $validation = $this->validator->validateTask($task);
            if (!$validation->isValid()) {
                throw new ValidationException($validation->getViolations());
            }

            // Schedule task execution
            $schedule = $this->scheduler->scheduleTask(
                $task,
                ExecutionPriority::CRITICAL
            );

            // Execute task
            $result = $this->processTask($task, $schedule, $executionId);

            // Verify execution
            $this->verifyExecution($result);

            $this->tracker->recordSuccess($executionId, $result);
            DB::commit();

            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleExecutionFailure($executionId, $task, $e);
            throw $e;
        }
    }

    private function processTask(
        CriticalTask $task,
        TaskSchedule $schedule,
        string $executionId
    ): ExecutionResult {
        // Create execution context
        $context = $this->createExecutionContext($task, $schedule);

        // Execute pre-task validations
        $this->validator->validatePreExecution($context);

        // Execute task with monitoring
        $result = $this->engine->executeTask($task, $context);

        if (!$result->isSuccessful()) {
            throw new ExecutionException($result->getError());
        }

        // Execute post-task validations
        $this->validator->validatePostExecution($result);

        return new ExecutionResult(
            success: true,
            executionId: $executionId,
            result: $result->getOutput(),
            metrics: $this->collectExecutionMetrics($result)
        );
    }

    private function verifyExecution(ExecutionResult $result): void
    {
        // Verify result integrity
        if (!$this->validator->verifyResultIntegrity($result)) {
            throw new IntegrityException('Execution result integrity check failed');
        }

        // Verify state consistency
        if (!$this->tracker->verifyStateConsistency($result)) {
            throw new StateException('Execution state consistency check failed');
        }

        // Verify output requirements
        if (!$this->validator->verifyOutputRequirements($result)) {
            throw new OutputException('Execution output requirements not met');
        }
    }

    private function handleExecutionFailure(
        string $executionId,
        CriticalTask $task,
        \Exception $e
    ): void {
        Log::critical('Task execution failed', [
            'execution_id' => $executionId,
            'task' => $task->toArray(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->emergency->handleExecutionFailure(
            $executionId,
            $task,
            $e
        );

        // Attempt task recovery if possible
        if ($task->isRecoverable()) {
            $this->attemptTaskRecovery($executionId, $task);
        }
    }

    private function attemptTaskRecovery(
        string $executionId,
        CriticalTask $task
    ): void {
        try {
            $recoveryPlan = $this->emergency->createRecoveryPlan($task);
            $this->emergency->executeRecovery($recoveryPlan);
        } catch (\Exception $recoveryError) {
            Log::emergency('Task recovery failed', [
                'execution_id' => $executionId,
                'error' => $recoveryError->getMessage()
            ]);
            $this->emergency->escalateFailure($executionId, $recoveryError);
        }
    }

    private function createExecutionContext(
        CriticalTask $task,
        TaskSchedule $schedule
    ): ExecutionContext {
        return new ExecutionContext([
            'task_id' => $task->getId(),
            'priority' => ExecutionPriority::CRITICAL,
            'schedule' => $schedule->toArray(),
            'requirements' => $task->getRequirements(),
            'constraints' => $task->getConstraints(),
            'timestamp' => now()
        ]);
    }

    private function collectExecutionMetrics(TaskResult $result): array
    {
        return [
            'execution_time' => $result->getExecutionTime(),
            'memory_usage' => $result->getMemoryUsage(),
            'cpu_usage' => $result->getCpuUsage(),
            'io_operations' => $result->getIoOperations(),
            'resource_utilization' => $result->getResourceUtilization()
        ];
    }

    public function getTaskStatus(string $taskId): TaskStatus
    {
        try {
            $status = $this->tracker->getTaskStatus($taskId);

            if ($status->hasFailed()) {
                $this->handleFailedStatus($status);
            }

            return $status;

        } catch (\Exception $e) {
            $this->handleStatusCheckFailure($taskId, $e);
            throw new StatusException(
                'Task status check failed',
                previous: $e
            );
        }
    }

    private function handleFailedStatus(TaskStatus $status): void
    {
        $this->emergency->handleFailedStatus(
            $status->getTaskId(),
            $status->getFailureReason()
        );
    }

    private function handleStatusCheckFailure(
        string $taskId,
        \Exception $e
    ): void {
        $this->emergency->handleStatusCheckFailure([
            'task_id' => $taskId,
            'error' => $e->getMessage(),
            'timestamp' => now()
        ]);
    }
}
