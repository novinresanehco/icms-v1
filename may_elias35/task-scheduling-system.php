// File: app/Core/Scheduler/Manager/ScheduleManager.php
<?php

namespace App\Core\Scheduler\Manager;

class ScheduleManager
{
    protected TaskRegistry $registry;
    protected CronManager $cronManager;
    protected ExecutionManager $executor;
    protected LockManager $lockManager;

    public function schedule(Task $task, string $expression): void
    {
        $this->validateExpression($expression);
        
        $schedule = new Schedule([
            'task' => $task,
            'expression' => $expression,
            'next_run' => $this->cronManager->getNextRunDate($expression),
            'status' => ScheduleStatus::ACTIVE
        ]);

        $this->registry->register($schedule);
    }

    public function run(): void
    {
        $lock = $this->lockManager->acquire('scheduler');

        try {
            $dueTasks = $this->registry->getDueTasks();

            foreach ($dueTasks as $task) {
                if ($this->shouldRun($task)) {
                    $this->executor->execute($task);
                }
            }
        } finally {
            $this->lockManager->release($lock);
        }
    }

    protected function shouldRun(Task $task): bool
    {
        return $task->isDue() && !$task->isRunning() && $task->isEnabled();
    }
}

// File: app/Core/Scheduler/Execution/ExecutionManager.php
<?php

namespace App\Core\Scheduler\Execution;

class ExecutionManager
{
    protected ExecutionStore $store;
    protected QueueManager $queue;
    protected Executor $executor;
    protected MetricsCollector $metrics;

    public function execute(Task $task): void
    {
        $execution = new Execution([
            'task_id' => $task->getId(),
            'started_at' => now(),
            'status' => ExecutionStatus::RUNNING
        ]);

        try {
            $this->store->save($execution);

            if ($task->isAsync()) {
                $this->queue->push(new ExecuteTaskJob($task, $execution));
            } else {
                $this->executeSync($task, $execution);
            }
        } catch (\Exception $e) {
            $this->handleFailure($execution, $e);
        }
    }

    protected function executeSync(Task $task, Execution $execution): void
    {
        try {
            $result = $this->executor->run($task);
            $this->handleSuccess($execution, $result);
        } catch (\Exception $e) {
            $this->handleFailure($execution, $e);
        }
    }

    protected function handleSuccess(Execution $execution, $result): void
    {
        $execution->complete($result);
        $this->store->save($execution);
        $this->metrics->recordSuccess($execution);
    }
}

// File: app/Core/Scheduler/Task/TaskRegistry.php
<?php

namespace App\Core\Scheduler\Task;

class TaskRegistry
{
    protected Repository $repository;
    protected CronParser $cronParser;
    protected TaskValidator $validator;

    public function register(Schedule $schedule): void
    {
        $this->validator->validate($schedule);
        $this->repository->save($schedule);
    }

    public function getDueTasks(): array
    {
        return $this->repository->findBy([
            'status' => ScheduleStatus::ACTIVE,
            'next_run' => ['<=', now()]
        ]);
    }

    public function updateNextRun(Schedule $schedule): void
    {
        $schedule->setNextRun(
            $this->cronParser->getNextRunDate($schedule->getExpression())
        );
        $this->repository->save($schedule);
    }
}

// File: app/Core/Scheduler/Lock/LockManager.php
<?php

namespace App\Core\Scheduler\Lock;

class LockManager
{
    protected LockStore $store;
    protected LockConfig $config;
    protected CleanupManager $cleanup;

    public function acquire(string $name, int $timeout = 0): Lock
    {
        $lock = new Lock($name);

        if (!$this->store->acquire($lock, $timeout)) {
            throw new LockException("Could not acquire lock: {$name}");
        }

        return $lock;
    }

    public function release(Lock $lock): void
    {
        $this->store->release($lock);
    }

    public function isLocked(string $name): bool
    {
        return $this->store->exists($name);
    }
}
