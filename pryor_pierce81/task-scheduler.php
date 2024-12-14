<?php

namespace App\Core\Scheduler;

class TaskScheduler
{
    private array $tasks = [];
    private TaskRepository $repository;
    private TaskExecutor $executor;
    private LockManager $lockManager;

    public function schedule(Task $task): void
    {
        $this->repository->save($task);
        $this->tasks[$task->getId()] = $task;
    }

    public function run(): void
    {
        $lock = $this->lockManager->acquire('scheduler');

        try {
            foreach ($this->repository->getDueTasks() as $task) {
                if ($this->shouldRun($task)) {
                    $this->executeTask($task);
                }
            }
        } finally {
            $this->lockManager->release($lock);
        }
    }

    private function shouldRun(Task $task): bool
    {
        return $task->isDue() && !$this->lockManager->isLocked($task->getId());
    }

    private function executeTask(Task $task): void
    {
        $taskLock = $this->lockManager->acquire($task->getId());

        try {
            $result = $this->executor->execute($task);
            $this->repository->updateLastRun($task, $result);
        } catch (\Exception $e) {
            $this->repository->recordFailure($task, $e);
            throw $e;
        } finally {
            $this->lockManager->release($taskLock);
        }
    }
}

class Task
{
    private string $id;
    private string $name;
    private string $command;
    private string $expression;
    private ?\DateTime $lastRun = null;
    private array $options = [];

    public function __construct(string $name, string $command, string $expression)
    {
        $this->id = uniqid('task_', true);
        $this->name = $name;
        $this->command = $command;
        $this->expression = $expression;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCommand(): string
    {
        return $this->command;
    }

    public function getExpression(): string
    {
        return $this->expression;
    }

    public function isDue(): bool
    {
        $cron = new \Cron\CronExpression($this->expression);
        return $cron->isDue();
    }

    public function setLastRun(\DateTime $lastRun): void
    {
        $this->lastRun = $lastRun;
    }

    public function getLastRun(): ?\DateTime
    {
        return $this->lastRun;
    }

    public function setOption(string $key, $value): void
    {
        $this->options[$key] = $value;
    }

    public function getOption(string $key, $default = null)
    {
        return $this->options[$key] ?? $default;
    }
}

class TaskExecutor
{
    private OutputHandler $outputHandler;
    private array $environments = [];

    public function execute(Task $task): TaskResult
    {
        $startTime = microtime(true);

        try {
            $output = $this->runCommand($task->getCommand());
            $exitCode = 0;
        } catch (\Exception $e) {
            $output = $e->getMessage();
            $exitCode = 1;
        }

        $endTime = microtime(true);
        $duration = $endTime - $startTime;

        return new TaskResult($exitCode, $output, $duration);
    }

    private function runCommand(string $command): string
    {
        $process = proc_open($command, [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ], $pipes);

        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        proc_close($process);

        if (!empty($errors)) {
            throw new \RuntimeException($errors);
        }

        return $output;
    }
}

class TaskResult
{
    private int $exitCode;
    private string $output;
    private float $duration;

    public function __construct(int $exitCode, string $output, float $duration)
    {
        $this->exitCode = $exitCode;
        $this->output = $output;
        $this->duration = $duration;
    }

    public function isSuccessful(): bool
    {
        return $this->exitCode === 0;
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    public function getOutput(): string
    {
        return $this->output;
    }

    public function getDuration(): float
    {
        return $this->duration;
    }
}

class TaskRepository
{
    private $connection;

    public function save(Task $task): void
    {
        $this->connection->table('scheduled_tasks')->insert([
            'id' => $task->getId(),
            'name' => $task->getName(),
            'command' => $task->getCommand(),
            'expression' => $task->getExpression(),
            'last_run' => $task->getLastRun(),
            'options' => json_encode($task->getOptions()),
            'created_at' => now()
        ]);
    }

    public function getDueTasks(): array
    {
        return $this->connection->table('scheduled_tasks')
            ->where(function($query) {
                $query->whereNull('last_run')
                    ->orWhere('last_run', '<', now()->subMinutes(5));
            })
            ->get()
            ->map(function($row) {
                return $this->hydrateTask($row);
            })
            ->toArray();
    }

    public function updateLastRun(Task $task, TaskResult $result): void
    {
        $this->connection->table('scheduled_tasks')
            ->where('id', $task->getId())
            ->update([
                'last_run' => now(),
                'last_result' => json_encode([
                    'exit_code' => $result->getExitCode(),
                    'output' => $result->getOutput(),
                    'duration' => $result->getDuration()
                ])
            ]);
    }

    public function recordFailure(Task $task, \Exception $e): void
    {
        $this->connection->table('task_failures')->insert([
            'task_id' => $task->getId(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'failed_at' => now()
        ]);
    }

    private function hydrateTask($row): Task
    {
        $task = new Task($row->name, $row->command, $row->expression);
        if ($row->last_run) {
            $task->setLastRun(new \DateTime($row->last_run));
        }
        if ($row->options) {
            foreach (json_decode($row->options, true) as $key => $value) {
                $task->setOption($key, $value);
            }
        }
        return $task;
    }
}

class LockManager
{
    private array $locks = [];
    private string $lockPath;

    public function acquire(string $key): Lock
    {
        $lockFile = $this->lockPath . '/' . $key . '.lock';
        $handle = fopen($lockFile, 'c');
        
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException("Could not acquire lock for: " . $key);
        }

        $lock = new Lock($key, $handle);
        $this->locks[$key] = $lock;
        
        return $lock;
    }

    public function release(Lock $lock): void
    {
        $key = $lock->getKey();
        if (isset($this->locks[$key])) {
            flock($lock->getHandle(), LOCK_UN);
            fclose($lock->getHandle());
            unlink($this->lockPath . '/' . $key . '.lock');
            unset($this->locks[$key]);
        }
    }

    public function isLocked(string $key): bool
    {
        return isset($this->locks[$key]) || file_exists($this->lockPath . '/' . $key . '.lock');
    }
}

class Lock
{
    private string $key;
    private $handle;

    public function __construct(string $key, $handle)
    {
        $this->key = $key;
        $this->handle = $handle;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getHandle()
    {
        return $this->handle;
    }
}
