<?php

namespace App\Core\Notification\Analytics\Queue;

class AnalyticsQueue
{
    private const MAX_RETRY_ATTEMPTS = 3;
    private const RETRY_DELAY = 300; // 5 minutes
    private array $queues;

    public function __construct()
    {
        $this->queues = [
            'analytics' => new PriorityQueue(),
            'reports' => new PriorityQueue(),
            'exports' => new PriorityQueue()
        ];
    }

    public function enqueue(string $queueName, array $job, int $priority = 0): string
    {
        $jobId = $this->generateJobId();
        $job['id'] = $jobId;
        $job['attempts'] = 0;
        $job['status'] = 'pending';
        $job['created_at'] = time();

        $this->queues[$queueName]->enqueue($job, $priority);
        
        return $jobId;
    }

    public function dequeue(string $queueName): ?array
    {
        return $this->queues[$queueName]->dequeue();
    }

    public function requeue(string $queueName, array $job): void
    {
        if ($job['attempts'] >= self::MAX_RETRY_ATTEMPTS) {
            $this->handleFailedJob($queueName, $job);
            return;
        }

        $job['attempts']++;
        $job['next_attempt'] = time() + self::RETRY_DELAY;
        
        $this->queues[$queueName]->enqueue($job, -1);
    }

    public function peek(string $queueName): ?array
    {
        return $this->queues[$queueName]->peek();
    }

    public function remove(string $queueName, string $jobId): bool
    {
        return $this->queues[$queueName]->remove($jobId);
    }

    public function clear(string $queueName): void
    {
        $this->queues[$queueName]->clear();
    }

    public function getStatus(string $queueName, string $jobId): ?array
    {
        return $this->queues[$queueName]->getStatus($jobId);
    }

    public function getQueueStats(string $queueName): array
    {
        return [
            'size' => $this->queues[$queueName]->size(),
            'processing' => $this->queues[$queueName]->getProcessingCount(),
            'failed' => $this->queues[$queueName]->getFailedCount(),
            'completed' => $this->queues[$queueName]->getCompletedCount()
        ];
    }

    private function generateJobId(): string
    {
        return uniqid('job_', true);
    }

    private function handleFailedJob(string $queueName, array $job): void
    {
        $job['status'] = 'failed';
        $job['failed_at'] = time();
        
        event(new AnalyticsJobFailed($queueName, $job));
        
        $this->queues[$queueName]->markAsFailed($job['id']);
    }
}

class PriorityQueue
{
    private array $elements = [];
    private array $status = [];
    private int $processingCount = 0;
    private int $failedCount = 0;
    private int $completedCount = 0;

    public function enqueue(array $element, int $priority): void
    {
        $this->elements[] = [
            'data' => $element,
            'priority' => $priority
        ];
        
        usort($this->elements, function($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });

        $this->status[$element['id']] = [
            'status' => $element['status'],
            'attempts' => $element['attempts'],
            'created_at' => $element['created_at']
        ];
    }

    public function dequeue(): ?array
    {
        if (empty($this->elements)) {
            return null;
        }

        $element = array_shift($this->elements);
        $this->processingCount++;
        
        $this->status[$element['data']['id']]['status'] = 'processing';
        
        return $element['data'];
    }

    public function peek(): ?array
    {
        if (empty($this->elements)) {
            return null;
        }

        return $this->elements[0]['data'];
    }

    public function remove(string $id): bool
    {
        foreach ($this->elements as $key => $element) {
            if ($element['data']['id'] === $id) {
                unset($this->elements[$key]);
                unset($this->status[$id]);
                $this->elements = array_values($this->elements);
                return true;
            }
        }
        
        return false;
    }

    public function clear(): void
    {
        $this->elements = [];
        $this->status = [];
        $this->processingCount = 0;
        $this->failedCount = 0;
        $this->completedCount = 0;
    }

    public function getStatus(string $id): ?array
    {
        return $this->status[$id] ?? null;
    }

    public function markAsFailed(string $id): void
    {
        if (isset($this->status[$id])) {
            $this->status[$id]['status'] = 'failed';
            $this->failedCount++;
            $this->processingCount--;
        }
    }

    public function markAsCompleted(string $id): void
    {
        if (isset($this->status[$id])) {
            $this->status[$id]['status'] = 'completed';
            $this->completedCount++;
            $this->processingCount--;
        }
    }

    public function size(): int
    {
        return count($this->elements);
    }

    public function getProcessingCount(): int
    {
        return $this->processingCount;
    }

    public function getFailedCount(): int
    {
        return $this->failedCount;
    }

    public function getCompletedCount(): int
    {
        return $this->completedCount;
    }
}
