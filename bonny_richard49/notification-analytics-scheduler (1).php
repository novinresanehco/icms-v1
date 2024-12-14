<?php

namespace App\Core\Notification\Analytics\Scheduler;

class AnalyticsScheduler
{
    private array $tasks = [];
    private array $running = [];
    private array $completed = [];
    private array $failed = [];

    public function schedule(string $taskId, callable $task, array $config = []): void
    {
        $this->tasks[$taskId] = [
            'task' => $task,
            'config' => array_merge([
                'start_time' => time(),
                'interval' => null,
                'priority' => 1,
                'timeout' => 3600,
                'retry_count' => 3
            ], $config),
            'status' => 'pending'
        ];
    }

    public function run(): void
    {
        $tasks = $this->getScheduledTasks();

        foreach ($tasks as $taskId => $task) {
            if ($this->shouldRunTask($task)) {
                $this->executeTask($taskId, $task);
            }
        }

        $this->checkTimeouts();
    }