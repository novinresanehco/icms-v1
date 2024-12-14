<?php

namespace App\Core\Monitoring\Jobs\Execution;

class JobExecutor {
    private JobProcessor $processor;
    private ResourceManager $resourceManager;
    private ExecutionLogger $logger;
    private RetryManager $retryManager;
    private JobLockManager $lockManager;

    public function execute(Job $job): ExecutionResult 
    {
        $lock = $this->lockManager->acquire($job);
        try {
            if (!$this->canExecute($job)) {
                return new ExecutionResult(false, 'Cannot execute job at this time');
            }

            $resources = $this->resourceManager->allocate($job);
            try {
                $result = $this->processor->process($job);
                $this->logger->logExecution($job, $result);
                
                if (!$result->isSuccess() && $this->retryManager->shouldRetry($job, $result)) {
                    $this->retryManager->scheduleRetry($job);
                }

                return $result;
            } finally {
                $this->resourceManager->release($resources);
            }
        } finally {
            $this->lockManager->release($lock);
        }
    }

    private function canExecute(Job $job): bool 
    {
        return $this->resourceManager->hasAvailableResources($job->getRequirements()) &&
               !$this->processor->isProcessing($job->getId());
    }
}

class JobProcessor {
    private array $handlers;
    private ExecutionContext $context;
    private ProcessMonitor $monitor;

    public function process(Job $job): ExecutionResult 
    {
        $handler = $this->getHandler($job);
        if (!$handler) {
            return new ExecutionResult(false, 'No handler found for job type');
        }

        $this->context->begin($job);
        $this->monitor->startMonitoring($job);

        try {
            $result = $handler->handle($job);
            $this->context->end($result);
            return $result;
        } catch (\Exception $e) {
            $this->context->fail($e);
            return new ExecutionResult(false, $e->getMessage());
        } finally {
            $this->monitor->stopMonitoring($job);
        }
    }

    private function getHandler(Job $job): ?JobHandler 
    {
        return $this->handlers[$job->getType()] ?? null;
    }
}

class RetryManager {
    private array $policies;
    private JobScheduler $scheduler;
    private array $retryHistory;

    public function shouldRetry(Job $job, ExecutionResult $result): bool 
    {
        $policy = $this->getRetryPolicy($job);
        if (!$policy) {
            return false;
        }

        $attempts = $this->getAttemptCount($job);
        return $policy->shouldRetry($result, $attempts);
    }

    public function scheduleRetry(Job $job): void 
    {
        $policy = $this->getRetryPolicy($job);
        $attempts = $this->getAttemptCount($job);
        
        $delay = $policy->getDelay($attempts);
        $this->scheduler->scheduleWithDelay($job, $delay);
        
        $this->retryHistory[$job->getId()][] = [
            'attempt' => $attempts + 1,
            'scheduled_at' => time(),
            'delay' => $delay
        ];
    }

    private function getRetryPolicy(Job $job): ?RetryPolicy 
    {
        return $this->policies[$job->getType()] ?? null;
    }

    private function getAttemptCount(Job $job): int 
    {
        return count($this->retryHistory[$job->getId()] ?? []);
    }
}

class ExecutionResult {
    private bool $success;
    private string $message;
    private array $data;
    private float $executionTime;
    private array $metrics;

    public function __construct(
        bool $success,
        string $message,
        array $data = [],
        float $executionTime = 0.0,
        array $metrics = []
    ) {
        $this->success = $success;
        $this->message = $message;
        $this->data = $data;
        $this->executionTime = $executionTime;
        $this->metrics = $metrics;
    }

    public function isSuccess(): bool 
    {
        return $this->success;
    }

    public function getMessage(): string 
    {
        return $this->message;
    }

    public function getData(): array 
    {
        return $this->data;
    }

    public function getExecutionTime(): float 
    {
        return $this->executionTime;
    }

    public function getMetrics(): array 
    {
        return $this->metrics;
    }
}

