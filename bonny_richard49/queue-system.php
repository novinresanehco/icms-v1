<?php

namespace App\Core\Queue\Contracts;

interface QueueManagerInterface
{
    public function push(Job $job, string $queue = null): string;
    public function later(Carbon $delay, Job $job, string $queue = null): string;
    public function bulk(array $jobs, string $queue = null): array;
    public function retry(string $jobId): bool;
    public function delete(string $jobId): bool;
}

interface JobInterface
{
    public function handle(): void;
    public function failed(\Exception $e): void;
    public function retryUntil(): ?Carbon;
    public function maxAttempts(): int;
}

namespace App\Core\Queue\Services;

class QueueManager implements QueueManagerInterface
{
    protected ConnectionFactory $factory;
    protected JobSerializer $serializer;
    protected FailureHandler $failureHandler;
    protected QueueMonitor $monitor;

    public function __construct(
        ConnectionFactory $factory,
        JobSerializer $serializer,
        FailureHandler $failureHandler,
        QueueMonitor $monitor
    ) {
        $this->factory = $factory;
        $this->serializer = $serializer;
        $this->failureHandler = $failureHandler;
        $this->monitor = $monitor;
    }

    public function push(Job $job, string $queue = null): string
    {
        $connection = $this->getConnection($job);
        $serialized = $this->serializer->serialize($job);
        
        $jobId = $connection->push($serialized, $queue);
        
        $this->monitor->jobPushed($jobId, $job);
        
        return $jobId;
    }

    public function later(Carbon $delay, Job $job, string $queue = null): string
    {
        $connection = $this->getConnection($job);
        $serialized = $this->serializer->serialize($job);
        
        $jobId = $connection->later($delay, $serialized, $queue);
        
        $this->monitor->jobScheduled($jobId, $job, $delay);
        
        return $jobId;
    }

    public function bulk(array $jobs, string $queue = null): array
    {
        $jobIds = [];
        $connection = $this->getConnection($jobs[0]);
        
        $serializedJobs = array_map(
            fn($job) => $this->serializer->serialize($job),
            $jobs
        );
        
        $jobIds = $connection->bulk($serializedJobs, $queue);
        
        foreach ($jobs as $index => $job) {
            $this->monitor->jobPushed($jobIds[$index], $job);
        }
        
        return $jobIds;
    }

    public function retry(string $jobId): bool
    {
        $failedJob = $this->failureHandler->get($jobId);
        if (!$failedJob) {
            return false;
        }

        $job = $this->serializer->unserialize($failedJob->getPayload());
        $this->push($job, $failedJob->getQueue());
        
        $this->failureHandler->forget($jobId);
        
        return true;
    }

    public function delete(string $jobId): bool
    {
        $connection = $this->factory->getDefaultConnection();
        return $connection->deleteReserved($jobId);
    }

    protected function getConnection(Job $job): QueueConnection
    {
        return $this->factory->connection($job->connection ?? null);
    }
}

namespace App\Core\Queue\Services;

class Worker
{
    protected QueueManager $manager;
    protected ExceptionHandler $exceptions;
    protected EventDispatcher $events;
    protected Cache $cache;

    protected bool $shouldQuit = false;

    public function daemon(string $queue = null, array $options = []): void
    {
        while (!$this->shouldQuit) {
            $this->runNextJob($queue, $options);
            
            if ($this->shouldSleep($queue)) {
                $this->sleep($options['sleep'] ?? 3);
            }
        }
    }

    public function runNextJob(string $queue = null, array $options = []): void
    {
        try {
            $connection = $this->manager->connection();
            
            $job = $this->getNextJob($connection, $queue);
            
            if ($job) {
                $this->process($job, $options);
            }
        } catch (\Exception $e) {
            $this->exceptions->report($e);
        }
    }

    protected function process(Job $job, array $options = []): void
    {
        try {
            $this->events->dispatch(new JobProcessing($job));
            
            $job->fire();
            
            $this->events->dispatch(new JobProcessed($job));
        } catch (\Exception $e) {
            $this->handleJobFailure($job, $e);
        }
    }

    protected function handleJobFailure(Job $job, \Exception $e): void
    {
        try {
            $this->events->dispatch(new JobFailed($job, $e));
            
            $job->failed($e);
            
            if ($job->shouldRetry()) {
                $this->manager->retry($job->getId());
            } else {
                $this->manager->delete($job->getId());
            }
        } catch (\Exception $e) {
            $this->exceptions->report($e);
        }
    }

    protected function shouldSleep(string $queue = null): bool
    {
        return $this->getQueueSize($queue) === 0;
    }

    protected function getQueueSize(string $queue = null): int
    {
        $cacheKey = "queue:size:{$queue}";
        
        return $this->cache->remember($cacheKey, 5, function () use ($queue) {
            return $this->manager->connection()->size($queue);
        });
    }
}

namespace App\Core\Queue\Jobs;

abstract class Job implements JobInterface
{
    protected string $id;
    protected string $queue;
    protected int $attempts = 0;
    protected ?Carbon $retryUntil = null;

    public function getId(): string
    {
        return $this->id;
    }

    public function getQueue(): string
    {
        return $this->queue;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function retryUntil(): ?Carbon
    {
        return $this->retryUntil;
    }

    public function maxAttempts(): int
    {
        return 3;
    }

    public function shouldRetry(): bool
    {
        if ($this->attempts >= $this->maxAttempts()) {
            return false;
        }

        if ($this->retryUntil && $this->retryUntil->isPast()) {
            return false;
        }

        return true;
    }

    public function failed(\Exception $e): void
    {
        // Default implementation
    }
}

namespace App\Core\Queue\Monitoring;

class QueueMonitor
{
    protected MetricsCollector $metrics;
    protected EventDispatcher $events;
    protected LoggerInterface $logger;

    public function jobPushed(string $jobId, Job $job): void
    {
        $this->metrics->increment('queue.jobs.pushed', 1, [
            'queue' => $job->getQueue(),
            'type' => get_class($job)
        ]);

        $this->logger->info('Job pushed to queue', [
            'job_id' => $jobId,
            'type' => get_class($job),
            'queue' => $job->getQueue()
        ]);
    }

    public function jobStarted(string $jobId, Job $job): void
    {
        $this->metrics->increment('queue.jobs.started', 1, [
            'queue' => $job->getQueue(),
            'type' => get_class($job)
        ]);

        $this->events->dispatch(new JobStarted($jobId, $job));
    }

    public function jobCompleted(string $jobId, Job $job): void
    {
        $this->metrics->increment('queue.jobs.completed', 1, [
            'queue' => $job->getQueue(),
            'type' => get_class($job)
        ]);

        $this->events->dispatch(new JobCompleted($jobId, $job));
    }

    public function jobFailed(string $jobId, Job $job, \Exception $e): void
    {
        $this->metrics->increment('queue.jobs.failed', 1, [
            'queue' => $job->getQueue(),
            'type' => get_class($job),
            'exception' => get_class($e)
        ]);

        $this->logger->error('Job failed', [
            'job_id' => $jobId,
            'type' => get_class($job),
            'queue' => $job->getQueue(),
            'exception' => $e->getMessage(),
            'stack_trace' => $e->getTraceAsString()
        ]);

        $this->events->dispatch(new JobFailed($jobId, $job, $e));
    }

    public function getQueueStats(): array
    {
        return [
            'size' => $this->metrics->gauge('queue.size'),
            'processed' => $this->metrics->counter('queue.jobs.completed'),
            'failed' => $this->metrics->counter('queue.jobs.failed'),
            'processing' => $this->metrics->gauge('queue.jobs.processing')
        ];
    }
}
