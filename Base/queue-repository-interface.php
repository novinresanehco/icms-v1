<?php

namespace App\Core\Repositories\Contracts;

use Illuminate\Support\Collection;

interface QueueRepositoryInterface
{
    public function getQueueStats(): array;
    
    public function getFailedJobs(string $queue, int $limit = 10): Collection;
    
    public function retryFailedJob(string $id): bool;
    
    public function retryAllFailed(string $queue): int;
    
    public function clearQueue(string $queue): bool;
    
    public function getJobsInQueue(string $queue, int $start = 0, int $end = -1): Collection;
    
    public function getWorkerStatus(): Collection;
}
