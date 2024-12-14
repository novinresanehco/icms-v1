<?php

namespace App\Core\Queue\Services;

use App\Core\Queue\Models\QueueJob;
use App\Core\Queue\Repositories\QueueRepository;
use Illuminate\Support\Facades\DB;

class QueueService
{
    public function __construct(
        private QueueRepository $repository,
        private QueueValidator $validator,
        private QueueDispatcher $dispatcher
    ) {}

    public function dispatch(string $jobType, array $data, array $options = []): QueueJob
    {
        $this->validator->validateJob($jobType, $data);

        return DB::transaction(function () use ($jobType, $data, $options) {
            $job = $this->repository->create([
                'type' => $jobType,
                'data' => $data,
                'status' => 'pending',
                'priority' => $options['priority'] ?? 'normal',
                'queue' => $options['queue'] ?? 'default',
                'delay' => $options['delay'] ?? null,
                'attempts' => 0,
                'max_attempts' => $options['max_attempts'] ?? 3
            ]);

            $this->dispatcher->dispatch($job);
            return $job;
        });
    }

    public function dispatchBatch(array $jobs, array $options = []): array
    {
        $results = [];

        foreach ($jobs as $job) {
            $results[] = $this->dispatch(
                $job['type'],
                $job['data'],
                array_merge($options, $job['options'] ?? [])
            );
        }

        return $results;
    }

    public function retry(QueueJob $job): bool
    {
        if (!$job->canRetry()) {
            throw new QueueException('Maximum retry attempts reached');
        }

        $job->incrementAttempts();
        $this->repository->updateStatus($job, 'pending');
        $this->dispatcher->dispatch($job);

        return true;
    }

    public function cancel(QueueJob $job): bool
    {
        if (!$job->canCancel()) {
            throw new QueueException('Cannot cancel job in current status');
        }

        return $this->repository->updateStatus($job, 'cancelled');
    }

    public function getJobStatus(int $jobId): array
    {
        $job = $this->repository->findOrFail($jobId);
        
        return [
            'status' => $job->status,
            'attempts' => $job->attempts,
            'progress' => $job->progress,
            'result' => $job->result,
            'error' => $job->error
        ];
    }

    public function listJobs(array $filters = []): Collection
    {
        return $this->repository->getWithFilters($filters);
    }

    public function cleanupJobs(int $olderThanDays = 30): int
    {
        return $this->repository->cleanup($olderThanDays);
    }

    public function getQueueStats(): array
    {
        return $this->repository->getStats();
    }
}
