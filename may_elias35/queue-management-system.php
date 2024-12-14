// File: app/Core/Queue/QueueManager.php
<?php

namespace App\Core\Queue;

class QueueManager
{
    protected QueueRegistry $queueRegistry;
    protected JobDispatcher $dispatcher;
    protected RetryManager $retryManager;
    protected QueueMonitor $monitor;

    public function __construct(
        QueueRegistry $queueRegistry,
        JobDispatcher $dispatcher,
        RetryManager $retryManager,
        QueueMonitor $monitor
    ) {
        $this->queueRegistry = $queueRegistry;
        $this->dispatcher = $dispatcher;
        $this->retryManager = $retryManager;
        $this->monitor = $monitor;
    }

    public function dispatch(Job $job, QueueConfig $config): DispatchResult
    {
        $queue = $this->selectQueue($job, $config);
        $preparedJob = $this->prepareJob($job, $queue, $config);
        
        try {
            $result = $this->dispatcher->dispatch($preparedJob, $queue);
            $this->monitor->recordDispatch($result);
            return $result;
        } catch (QueueException $e) {
            return $this->handleDispatchFailure($job, $e);
        }
    }

    protected function selectQueue(Job $job, QueueConfig $config): Queue
    {
        $queues = $this->queueRegistry->getAvailableQueues();
        return $this->queueRegistry->selectOptimalQueue($queues, $job, $config);
    }

    protected function prepareJob(Job $job, Queue $queue, QueueConfig $config): PreparedJob
    {
        return (new JobPreparer())
            ->setQueue($queue)
            ->setRetryPolicy($this->retryManager->getPolicy($job))
            ->setMiddleware($config->getMiddleware())
            ->prepare($job);
    }
}

// File: app/Core/Queue/JobProcessor.php
<?php

namespace App\Core\Queue;

class JobProcessor
{
    protected JobValidator $validator;
    protected MiddlewareRunner $middleware;
    protected MetricsCollector $metrics;
    protected ErrorHandler $errorHandler;

    public function process(PreparedJob $job): ProcessResult
    {
        try {
            $this->validator->validate($job);
            $this->middleware->before($job);
            
            $result = $job->handle();
            
            $this->middleware->after($job, $result);
            $this->metrics->record($job, $result);
            
            return $result;
        } catch (JobException $e) {
            return $this->handleFailure($job, $e);
        }
    }

    protected function handleFailure(PreparedJob $job, JobException $e): ProcessResult
    {
        $this->errorHandler->handle($e);
        
        if ($job->canRetry()) {
            return $this->retryJob($job);
        }
        
        return ProcessResult::failure($e);
    }
}

// File: app/Core/Queue/QueueWorker.php
<?php

namespace App\Core\Queue;

class QueueWorker
{
    protected JobProcessor $processor;
    protected QueueMonitor $monitor;
    protected WorkerConfig $config;

    public function work(Queue $queue): void
    {
        while ($this->shouldContinue()) {
            $this->processNextJob($queue);
            $this->monitor->checkHealth();
        }
    }

    protected function processNextJob(Queue $queue): void
    {
        if ($job = $queue->pop()) {
            try {
                $result = $this->processor->process($job);
                $this->monitor->recordProcessing($job, $result);
            } catch (WorkerException $e) {
                $this->monitor->recordFailure($job, $e);
            }
        } else {
            $this->sleep();
        }
    }

    protected function shouldContinue(): bool
    {
        return !$this->config->shouldStop() && 
               $this->monitor->isHealthy();
    }
}

// File: app/Core/Queue/RetryManager.php
<?php

namespace App\Core\Queue;

class RetryManager
{
    protected array $policies = [];
    protected RetryStrategy $strategy;
    protected BackoffCalculator $backoff;

    public function getPolicy(Job $job): RetryPolicy
    {
        return $this->policies[$job->getType()] ?? $this->getDefaultPolicy();
    }

    public function shouldRetry(Job $job, int $attempts): bool
    {
        $policy = $this->getPolicy($job);
        return $attempts < $policy->getMaxAttempts() &&
               $this->strategy->shouldRetry($job);
    }

    public function calculateNextRetry(Job $job, int $attempts): int
    {
        return $this->backoff->calculate($attempts, $job);
    }
}
