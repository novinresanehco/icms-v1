<?php

namespace App\Core\Infrastructure;

/**
 * Critical Queue Management System
 * Handles all asynchronous operations with comprehensive monitoring and failure handling
 */
class QueueManager implements QueueManagerInterface
{
    private SecurityManager $security;
    private DatabaseManager $database;
    private MetricsCollector $metrics;
    private AuditService $audit;
    private array $config;
    private array $queues = [];

    public function __construct(
        SecurityManager $security,
        DatabaseManager $database,
        MetricsCollector $metrics,
        AuditService $audit,
        array $config
    ) {
        $this->security = $security;
        $this->database = $database;
        $this->metrics = $metrics;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function push(string $queue, Job $job, array $options = []): bool
    {
        $startTime = microtime(true);

        try {
            // Validate job and permissions
            $this->validateJob($job);
            $this->security->validateQueueAccess($queue, $options);

            // Get queue driver
            $driver = $this->getQueueDriver($queue);

            // Prepare job for queue
            $preparedJob = $this->prepareJob($job, $options);
            
            // Push to queue with monitoring
            $result = $driver->push($preparedJob);
            
            // Record metrics and audit
            $this->metrics->recordQueueOperation('push', microtime(true) - $startTime);
            $this->audit->logQueueOperation('push', $queue, $job->getId());
            
            return $result;

        } catch (\Exception $e) {
            $this->handleQueueFailure($e, 'push', $queue, $job);
            throw new QueueException('Job push failed', 0, $e);
        }
    }

    public function process(string $queue, array $options = []): void
    {
        try {
            // Validate access
            $this->security->validateQueueAccess($queue, $options);
            
            // Get queue driver
            $driver = $this->getQueueDriver($queue);
            
            // Process jobs with monitoring
            while ($job = $driver->pop()) {
                $this->processJob($job, $options);
            }

        } catch (\Exception $e) {
            $this->handleQueueFailure($e, 'process', $queue);
            throw new QueueException('Queue processing failed', 0, $e);
        }
    }

    public function retry(string $queue, string $jobId, array $options = []): bool
    {
        try {
            // Validate access and get failed job
            $this->security->validateQueueAccess($queue, $options);
            $failedJob = $this->getFailedJob($jobId);
            
            // Prepare for retry
            $job = $this->prepareRetry($failedJob, $options);
            
            // Push back to queue
            $result = $this->push($queue, $job, $options);
            
            // Update failed job status
            $this->updateFailedJobStatus($jobId, 'retrying');
            
            return $result;

        } catch (\Exception $e) {
            $this->handleQueueFailure($e, 'retry', $queue, $jobId);
            throw new QueueException('Job retry failed', 0, $e);
        }
    }

    public function clear(string $queue, array $options = []): bool
    {
        try {
            // Validate access
            $this->security->validateQueueAccess($queue, $options);
            
            // Get queue driver
            $driver = $this->getQueueDriver($queue);
            
            // Clear queue with backup
            $this->backupQueue($queue);
            $result = $driver->clear();
            
            // Audit clear operation
            $this->audit->logQueueOperation('clear', $queue);
            
            return $result;

        } catch (\Exception $e) {
            $this->handleQueueFailure($e, 'clear', $queue);
            throw new QueueException('Queue clear failed', 0, $e);
        }
    }

    private function processJob(Job $job, array $options): void
    {
        $startTime = microtime(true);

        try {
            // Execute job
            $result = $job->handle();
            
            // Record success
            $this->recordJobSuccess($job, $result, microtime(true) - $startTime);

        } catch (\Exception $e) {
            // Handle job failure
            $this->handleJobFailure($job, $e);
            
            // Determine retry strategy
            if ($this->shouldRetryJob($job)) {
                $this->queueForRetry($job);
            } else {
                $this->markJobAsFailed($job, $e);
            }
        }
    }

    private function handleQueueFailure(\Exception $e, string $operation, string $queue, $context = null): void
    {
        // Log failure with full context
        Log::error('Queue operation failed', [
            'operation' => $operation,
            'queue' => $queue,
            'context' => $context,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        // Record metrics
        $this->metrics->recordFailure('queue.' . $operation);

        // Create audit log
        $this->audit->logQueueFailure($operation, $queue, $e);

        // Trigger alerts if needed
        if ($this->isFailureCritical($e)) {
            $this->triggerFailureAlert($e, $operation, $queue);
        }
    }

    private function validateJob(Job $job): void
    {
        if (!$job->isValid()) {
            throw new QueueException('Invalid job configuration');
        }
    }

    private function prepareJob(Job $job, array $options): Job
    {
        // Add metadata
        $job->addMetadata([
            'queued_at' => time(),
            'priority' => $options['priority'] ?? 'normal',
            'retry_count' => 0
        ]);

        // Encrypt sensitive data
        if ($job->hasSensitiveData()) {
            $job->encryptPayload($this->security);
        }

        return $job;
    }

    private function shouldRetryJob(Job $job): bool
    {
        return $job->getRetryCount() < $this->config['max_retries']
            && !$job->hasFailedPermanently();
    }

    private function recordJobSuccess(Job $job, $result, float $duration): void
    {
        // Record metrics
        $this->metrics->recordJobSuccess($job->getName(), $duration);
        
        // Log success
        $this->audit->logJobSuccess($job, $result);
        
        // Update job status
        $this->updateJobStatus($job, 'completed', $