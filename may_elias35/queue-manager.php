<?php

namespace App\Core\Queue;

class QueueManager
{
    private QueueRepository $repository;
    private QueueProcessor $processor;
    private FailureHandler $failureHandler;
    private QueueMonitor $monitor;
    private RetryPolicy $retryPolicy;

    public function __construct(
        QueueRepository $repository,
        QueueProcessor $processor,
        FailureHandler $failureHandler,
        QueueMonitor $monitor,
        RetryPolicy $retryPolicy
    ) {
        $this->repository = $repository;
        $this->processor = $processor;
        $this->failureHandler = $failureHandler;
        $this->monitor = $monitor;
        $this->retryPolicy = $retryPolicy;
    }

    public function push(Job $job, array $options = []): string
    {
        $jobId = $this->generateJobId();
        
        $this->validateJob($job);
        $this->repository->store($jobId, $job, $options);
        $this->monitor->recordPush($jobId, $job);
        
        if ($options['immediate'] ?? false) {
            $this->process($jobId);
        }
        
        return $jobId;
    }

    public function process(string $jobId): ProcessingResult
    {
        try {
            $job = $this->repository->get($jobId);
            
            if (!$job) {
                throw new JobNotFoundException($jobId);
            }
            
            $this->monitor->recordProcessingStart($jobId);
            $result = $this->processor->process($job);
            
            if ($result->isSuccess()) {
                $this->handleSuccess($jobId, $result);
            } else {
                $this->handleFailure($jobId, $result);
            }
            
            return $result;
            
        } catch (\Exception $e) {
            return $this->handleError($jobId, $e);
        }
    }

    public function retry(string $jobId): void
    {
        if (!$this->retryPolicy->canRetry($jobId)) {
            throw new MaxRetriesExceededException($jobId);
        }

        $job = $this->repository->get($jobId);
        $delay = $this->retryPolicy->getNextRetryDelay($jobId);
        
        $this->repository->markForRetry($jobId, $delay);
        $this->monitor->recordRetry($jobId);
    }

    public function cancel(string $jobId): void
    {
        $this->repository->remove($jobId);
        $this->monitor->recordCancellation($jobId);
    }

    public function getStatus(string $jobId): JobStatus
    {
        $job = $this->repository->get($jobId);
        
        if (!$job) {
            throw new JobNotFoundException($jobId);
        }
        
        return new JobStatus(
            $job,
            $this->repository->getAttempts($jobId),
            $this->repository->getLastError($jobId),
            $this->repository->getState($jobId)
        );
    }

    protected function handleSuccess(string $jobId, ProcessingResult $result): void
    {
        $this->repository->markComplete($jobId);
        $this->monitor->recordSuccess($jobId, $result);
    }

    protected function handleFailure(string $jobId, ProcessingResult $result): void
    {
        if ($this->retryPolicy->canRetry($jobId)) {
            $this->retry($jobId);
        } else {
            $this->failureHandler->handle($jobId, $result);
            $this->monitor->recordFailure($jobId, $result);
        }
    }

    protected function handleError(string $jobId, \Exception $e): ProcessingResult
    {
        $result = new ProcessingResult(false, ['error' => $e->getMessage()]);
        
        if ($this->retryPolicy->canRetry($jobId)) {
            $this->retry($jobId);
        } else {
            $this->failureHandler->handle($jobId, $result);
        }
        
        $this->monitor->recordError($jobId, $e);
        return $result;
    }

    protected function validateJob(Job $job): void
    {
        if (!$job->isValid()) {
            throw new InvalidJobException('Job validation failed');
        }
    }

    protected function generateJobId(): string
    {
        return uniqid('job_', true);
    }
}

class QueueProcessor
{
    private JobFactory $factory;
    private array $middleware = [];
    private MetricsCollector $metrics;

    public function process(Job $job): ProcessingResult
    {
        $startTime = microtime(true);

        try {
            $handler = $this->factory->createHandler($job);
            
            foreach ($this->middleware as $middleware) {
                $handler = $middleware($handler);
            }
            
            $result = $handler->handle($job);
            
            $this->metrics->recordProcessing(
                $job,
                microtime(true) - $startTime,
                true
            );
            
            return $result;
            
        } catch (\Exception $e) {
            $this->metrics->recordProcessing(
                $job,
                microtime(true) - $startTime,
                false
            );
            
            throw $e;
        }
    }

    public function addMiddleware(callable $middleware): void
    {
        $this->middleware[] = $middleware;
    }
}

class FailureHandler
{
    private LoggerInterface $logger;
    private NotificationService $notifications;
    private DeadLetterQueue $deadLetterQueue;

    public function handle(string $jobId, ProcessingResult $result): void
    {
        $this->logger->error('Job processing failed', [
            'job_id' => $jobId,
            'result' => $result->toArray()
        ]);
        
        $this->deadLetterQueue->push($jobId, $result);
        
        $this->notifications->notify('job.failed', [
            'job_id' => $jobId,
            'error' => $result->getError()
        ]);
    }
}

class QueueMonitor
{
    private MetricsCollector $metrics;
    private LoggerInterface $logger;
    private array $thresholds;

    public function recordPush(string $jobId, Job $job): void
    {
        $this->metrics->increment('jobs.pushed', ['type' => $job->getType()]);
        $this->logger->info('Job pushed to queue', ['job_id' => $jobId]);
    }

    public function recordProcessingStart(string $jobId): void
    {
        $this->metrics->increment('jobs.processing');
        $this->logger->info('Job processing started', ['job_id' => $jobId]);
    }

    public function recordSuccess(string $jobId, ProcessingResult $result): void
    {
        $this->metrics->increment('jobs.completed');
        $this->logger->info('Job completed successfully', ['job_id' => $jobId]);
    }

    public function recordFailure(string $jobId, ProcessingResult $result): void
    {
        $this->metrics->increment('jobs.failed');
        $this->logger->error('Job failed', [
            'job_id' => $jobId,
            'error' => $result->getError()
        ]);
    }

    public function recordRetry(string $jobId): void
    {
        $this->metrics->increment('jobs.retried');
        $this->logger->info('Job scheduled for retry', ['job_id' => $jobId]);
    }

    public function recordError(string $jobId, \Exception $e): void
    {
        $this->metrics->increment('jobs.errors');
        $this->logger->error('Job processing error', [
            'job_id' => $jobId,
            'error' => $e->getMessage()
        ]);
    }

    public function recordCancellation(string $jobId): void
    {
        $this->metrics->increment('jobs.cancelled');
        $this->logger->info('Job cancelled', ['job_id' => $jobId]);
    }

    public function checkThresholds(): void
    {
        $stats = $this->metrics->getStats();
        
        foreach ($this->thresholds as $metric => $threshold) {
            if (($stats[$metric] ?? 0) > $threshold) {
                $this->logger->warning('Queue metric threshold exceeded', [
                    'metric' => $metric,
                    'value' => $stats[$metric],
                    'threshold' => $threshold
                ]);
            }
        }
    }
}

class RetryPolicy
{
    private int $maxAttempts;
    private array $delays;
    private QueueRepository $repository;

    public function canRetry(string $jobId): bool
    {
        $attempts = $this->repository->getAttempts($jobId);
        return $attempts < $this->maxAttempts;
    }

    public function getNextRetryDelay(string $jobId): int
    {
        $attempts = $this->repository->getAttempts($jobId);
        return $this->delays[$attempts] ?? end($this->delays);
    }
}
