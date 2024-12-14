<?php

namespace App\Core\Jobs;

class JobDispatcher
{
    private QueueManager $queueManager;
    private JobSerializer $serializer;
    private RetryPolicy $retryPolicy;

    public function dispatch(Job $job): string
    {
        $jobId = uniqid('job_', true);
        $serializedJob = $this->serializer->serialize($job);
        
        $this->queueManager->push(
            $job->getQueue(),
            [
                'id' => $jobId,
                'job' => $serializedJob,
                'attempts' => 0,
                'created_at' => time()
            ]
        );

        return $jobId;
    }

    public function dispatchAfter(Job $job, int $delay): string
    {
        $jobId = uniqid('job_', true);
        $serializedJob = $this->serializer->serialize($job);
        
        $this->queueManager->pushDelayed(
            $job->getQueue(),
            [
                'id' => $jobId,
                'job' => $serializedJob,
                'attempts' => 0,
                'created_at' => time()
            ],
            $delay
        );

        return $jobId;
    }

    public function dispatchBatch(array $jobs): array
    {
        $jobIds = [];
        foreach ($jobs as $job) {
            $jobIds[] = $this->dispatch($job);
        }
        return $jobIds;
    }
}

class QueueManager
{
    private array $queues = [];
    private QueueConnector $connector;

    public function push(string $queue, array $payload): void
    {
        $this->connector->push($queue, $payload);
    }

    public function pushDelayed(string $queue, array $payload, int $delay): void
    {
        $this->connector->pushDelayed($queue, $payload, $delay);
    }

    public function pop(string $queue): ?array
    {
        return $this->connector->pop($queue);
    }

    public function acknowledge(string $queue, string $jobId): void
    {
        $this->connector->acknowledge($queue, $jobId);
    }

    public function fail(string $queue, string $jobId, \Exception $e): void
    {
        $this->connector->fail($queue, $jobId, [
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

class Worker
{
    private QueueManager $queueManager;
    private JobSerializer $serializer;
    private RetryPolicy $retryPolicy;
    private JobLogger $logger;

    public function work(array $queues): void
    {
        while (true) {
            foreach ($queues as $queue) {
                $payload = $this->queueManager->pop($queue);
                
                if ($payload) {
                    try {
                        $this->process($queue, $payload);
                    } catch (\Exception $e) {
                        $this->handleFailure($queue, $payload, $e);
                    }
                }
            }
            
            sleep(1);
        }
    }

    private function process(string $queue, array $payload): void
    {
        $job = $this->serializer->unserialize($payload['job']);
        
        try {
            $job->handle();
            $this->queueManager->acknowledge($queue, $payload['id']);
            $this->logger->logSuccess($payload['id']);
        } catch (\Exception $e) {
            throw $e;
        }
    }

    private function handleFailure(string $queue, array $payload, \Exception $e): void
    {
        $payload['attempts']++;
        
        if ($this->retryPolicy->shouldRetry($payload['attempts'])) {
            $delay = $this->retryPolicy->getDelay($payload['attempts']);
            $this->queueManager->pushDelayed($queue, $payload, $delay);
            $this->logger->logRetry($payload['id'], $payload['attempts']);
        } else {
            $this->queueManager->fail($queue, $payload['id'], $e);
            $this->logger->logFailure($payload['id'], $e);
        }
    }
}

abstract class Job
{
    private array $middleware = [];

    abstract public function handle(): void;

    public function getQueue(): string
    {
        return 'default';
    }

    public function middleware(): array
    {
        return $this->middleware;
    }

    protected function addMiddleware(JobMiddleware $middleware): void
    {
        $this->middleware[] = $middleware;
    }
}

interface JobMiddleware
{
    public function handle(Job $job, callable $next): void;
}

class RetryPolicy
{
    private int $maxAttempts;
    private array $delays;

    public function __construct(int $maxAttempts = 3, array $delays = [5, 15, 30])
    {
        $this->maxAttempts = $maxAttempts;
        $this->delays = $delays;
    }

    public function shouldRetry(int $attempts): bool
    {
        return $attempts < $this->maxAttempts;
    }

    public function getDelay(int $attempt): int
    {
        return $this->delays[$attempt - 1] ?? end($this->delays);
    }
}

class JobLogger
{
    private $connection;

    public function logSuccess(string $jobId): void
    {
        $this->connection->table('job_logs')->insert([
            'job_id' => $jobId,
            'status' => 'success',
            'logged_at' => now()
        ]);
    }

    public function logRetry(string $jobId, int $attempt): void
    {
        $this->connection->table('job_logs')->insert([
            'job_id' => $jobId,
            'status' => 'retry',
            'attempt' => $attempt,
            'logged_at' => now()
        ]);
    }

    public function logFailure(string $jobId, \Exception $e): void
    {
        $this->connection->table('job_logs')->insert([
            'job_id' => $jobId,
            'status' => 'failed',
            'error' => $e->getMessage(),
            'logged_at' => now()
        ]);
    }
}

class JobSerializer
{
    public function serialize(Job $job): string
    {
        return serialize($job);
    }

    public function unserialize(string $serialized): Job
    {
        return unserialize($serialized);
    }
}
