<?php

namespace App\Core\Repository;

use App\Models\QueueJob;
use App\Core\Events\QueueEvents;
use App\Core\Exceptions\QueueRepositoryException;

class QueueRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return QueueJob::class;
    }

    /**
     * Add job to queue
     */
    public function enqueue(string $type, array $data, array $options = []): QueueJob
    {
        try {
            $job = $this->create([
                'type' => $type,
                'data' => $data,
                'priority' => $options['priority'] ?? 'normal',
                'queue' => $options['queue'] ?? 'default',
                'attempts' => 0,
                'max_attempts' => $options['max_attempts'] ?? 3,
                'status' => 'pending',
                'available_at' => $options['delay'] 
                    ? now()->addSeconds($options['delay'])
                    : now()
            ]);

            event(new QueueEvents\JobEnqueued($job));
            return $job;

        } catch (\Exception $e) {
            throw new QueueRepositoryException(
                "Failed to enqueue job: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get next available job
     */
    public function getNextJob(string $queue = 'default'): ?QueueJob
    {
        try {
            return DB::transaction(function() use ($queue) {
                $job = $this->model
                    ->where('queue', $queue)
                    ->where('status', 'pending')
                    ->where('available_at', '<=', now())
                    ->orderBy('priority')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if ($job) {
                    $job->update([
                        'status' => 'processing',
                        'started_at' => now(),
                        'attempts' => $job->attempts + 1
                    ]);
                }

                return $job;
            });
        } catch (\Exception $e) {
            throw new QueueRepositoryException(
                "Failed to get next job: {$e->getMessage()}"
            );
        }
    }

    /**
     * Mark job as completed
     */
    public function markAsCompleted(int $jobId, array $result = []): void
    {
        try {
            $job = $this->find($jobId);
            if (!$job) {
                throw new QueueRepositoryException("Job not found with ID: {$jobId}");
            }

            $job->update([
                'status' => 'completed',
                'result' => $result,
                'completed_at' => now()
            ]);

            event(new QueueEvents\JobCompleted($job));

        } catch (\Exception $e) {
            throw new QueueRepositoryException(
                "Failed to mark job as completed: {$e->getMessage()}"
            );
        }
    }

    /**
     * Mark job as failed
     */
    public function markAsFailed(int $jobId, string $error): void
    {
        try {
            $job = $this->find($jobId);
            if (!$job) {
                throw new QueueRepositoryException("Job not found with ID: {$jobId}");
            }

            $status = $job->attempts >= $job->max_attempts ? 'failed' : 'pending';
            
            $job->update([
                'status' => $status,
                'last_error' => $error,
                'failed_at' => $status === 'failed' ? now() : null,
                'available_at' => $status === 'pending' 
                    ? now()->addSeconds($this->getBackoffDelay($job->attempts))
                    : null
            ]);

            event(new QueueEvents\JobFailed($job));

        } catch (\Exception $e) {
            throw new QueueRepositoryException(
                "Failed to mark job as failed: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get queue statistics
     */
    public function getQueueStats(string $queue = 'default'): array
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("stats.{$queue}"),
            60, // 1 minute cache
            fn() => [
                'pending' => $this->model->where('queue', $queue)
                                      ->where('status', 'pending')
                                      ->count(),
                'processing' => $this->model->where('queue', $queue)
                                         ->where('status', 'processing')
                                         ->count(),
                'completed' => $this->model->where('queue', $queue)
                                        ->where('status', 'completed')
                                        ->count(),
                'failed' => $this->model->where('queue', $queue)
                                     ->where('status', 'failed')
                                     ->count()
            ]
        );
    }

    /**
     * Calculate backoff delay
     */
    protected function getBackoffDelay(int $attempts): int
    {
        return min(pow(2, $attempts) * 10, 3600); // Max 1 hour delay
    }
}
