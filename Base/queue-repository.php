<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\QueueRepositoryInterface;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Collection;

class QueueRepository implements QueueRepositoryInterface
{
    public function getQueueStats(): array
    {
        $stats = [];
        $queues = config('queue.queues', ['default']);

        foreach ($queues as $queue) {
            $stats[$queue] = [
                'size' => $this->getQueueSize($queue),
                'failed' => $this->getFailedCount($queue),
                'processed' => $this->getProcessedCount($queue),
                'delayed' => $this->getDelayedCount($queue)
            ];
        }

        return $stats;
    }

    public function getFailedJobs(string $queue, int $limit = 10): Collection
    {
        return collect(Queue::failed()->get())
            ->filter(fn ($job) => $job->queue === $queue)
            ->take($limit);
    }

    public function retryFailedJob(string $id): bool
    {
        return Queue::retry($id);
    }

    public function retryAllFailed(string $queue): int
    {
        $count = 0;
        $failed = Queue::failed()->get();

        foreach ($failed as $job) {
            if ($job->queue === $queue) {
                if (Queue::retry($job->id)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    public function clearQueue(string $queue): bool
    {
        if (config('queue.default') === 'redis') {
            Redis::command('del', ["queues:{$queue}"]);
            return true;
        }

        return false;
    }

    public function getJobsInQueue(string $queue, int $start = 0, int $end = -1): Collection
    {
        if (config('queue.default') === 'redis') {
            $jobs = Redis::command('lrange', ["queues:{$queue}", $start, $end]);
            return collect($jobs)->map(fn ($job) => json_decode($job, true));
        }

        return collect();
    }

    public function getWorkerStatus(): Collection
    {
        if (config('queue.default') === 'redis') {
            $workers = Redis::command('keys', ['worker:*']);
            return collect($workers)->map(function ($worker) {
                return [
                    'id' => str_replace('worker:', '', $worker),
                    'status' => Redis::command('get', [$worker]),
                    'last_heartbeat' => Redis::command('get', ["worker:{$worker}:heartbeat"])
                ];
            });
        }

        return collect();
    }

    protected function getQueueSize(string $queue): int
    {
        if (config('queue.default') === 'redis') {
            return Redis::command('llen', ["queues:{$queue}"]) ?: 0;
        }

        return 0;
    }

    protected function getFailedCount(string $queue): int
    {
        return collect(Queue::failed()->get())
            ->filter(fn ($job) => $job->queue === $queue)
            ->count();
    }

    protected function getProcessedCount(string $queue): int
    {
        if (config('queue.default') === 'redis') {
            return (int) Redis::command('get', ["stats:processed:{$queue}"]) ?: 0;
        }

        return 0;
    }

    protected function getDelayedCount(string $queue): int
    {
        if (config('queue.default') === 'redis') {
            return Redis::command('zcard', ["queues:{$queue}:delayed"]) ?: 0;
        }

        return 0;
    }
}
