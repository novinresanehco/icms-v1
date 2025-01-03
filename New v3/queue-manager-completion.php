private function recordJobSuccess(Job $job, $result, float $duration): void
    {
        // Record metrics
        $this->metrics->recordJobSuccess($job->getName(), $duration);
        
        // Log success
        $this->audit->logJobSuccess($job, $result);
        
        // Update job status
        $this->updateJobStatus($job, 'completed', [
            'completed_at' => time(),
            'duration' => $duration,
            'result' => $result
        ]);

        // Clean up resources
        $this->cleanupJobResources($job);
    }

    private function handleJobFailure(Job $job, \Exception $e): void
    {
        // Record failure metrics
        $this->metrics->recordJobFailure($job->getName(), get_class($e));

        // Log detailed failure
        $this->audit->logJobFailure($job, $e);

        // Increment retry count
        $job->incrementRetryCount();

        // Store failure details
        $this->storeFailureDetails($job, $e);

        // Notify if critical
        if ($job->isCritical()) {
            $this->notifyCriticalJobFailure($job, $e);
        }
    }

    private function queueForRetry(Job $job): void
    {
        // Calculate next retry time with exponential backoff
        $delay = $this->calculateRetryDelay($job);

        // Prepare job for retry
        $job->prepareForRetry([
            'retry_count' => $job->getRetryCount(),
            'last_error' => $job->getLastError(),
            'retry_at' => time() + $delay
        ]);

        // Push to retry queue
        $this->push('retry', $job, ['delay' => $delay]);
    }

    private function markJobAsFailed(Job $job, \Exception $e): void
    {
        // Update job status
        $this->updateJobStatus($job, 'failed', [
            'failed_at' => time(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        // Move to failed jobs storage
        $this->storeFailedJob($job);

        // Clean up queue
        $this->cleanupFailedJob($job);
    }

    private function calculateRetryDelay(Job $job): int
    {
        $baseDelay = $this->config['retry_delay'] ?? 60;
        $attempt = $job->getRetryCount();
        
        // Exponential backoff with jitter
        $delay = min(
            $baseDelay * pow(2, $attempt),
            $this->config['max_retry_delay'] ?? 3600
        );
        
        // Add random jitter
        return $delay + rand(0, min(30, $delay));
    }

    private function updateJobStatus(Job $job, string $status, array $metadata = []): void
    {
        $this->database->transaction(function() use ($job, $status, $metadata) {
            $this->database->table('jobs')
                ->where('id', $job->getId())
                ->update([
                    'status' => $status,
                    'metadata' => json_encode(array_merge(
                        $job->getMetadata(),
                        $metadata
                    )),
                    'updated_at' => time()
                ]);
        });
    }

    private function storeFailureDetails(Job $job, \Exception $e): void
    {
        $this->database->transaction(function() use ($job, $e) {
            $this->database->table('job_failures')->insert([
                'job_id' => $job->getId(),
                'queue' => $job->getQueue(),
                'payload' => json_encode($job->getPayload()),
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'failed_at' => time()
            ]);
        });
    }

    private function notifyCriticalJobFailure(Job $job, \Exception $e): void
    {
        $this->audit->notifyJobFailure($job, $e, [
            'priority' => 'critical',
            'retry_count' => $job->getRetryCount(),
            'last_retry' => $job->getLastRetryTime()
        ]);
    }

    private function cleanupJobResources(Job $job): void
    {
        // Remove temporary files
        if ($files = $job->getTemporaryFiles()) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }

        // Clear temporary data
        if ($keys = $job->getTemporaryCacheKeys()) {
            foreach ($keys as $key) {
                $this->cache->forget($key);
            }
        }
    }

    private function isFailureCritical(\Exception $e): bool
    {
        return $e instanceof CriticalQueueException
            || $e instanceof SystemException
            || $e instanceof SecurityException;
    }

    private function backupQueue(string $queue): void
    {
        $driver = $this->getQueueDriver($queue);
        $jobs = $driver->all();
        
        $this->database->transaction(function() use ($queue, $jobs) {
            foreach ($jobs as $job) {
                $this->database->table('queue_backups')->insert([
                    'queue' => $queue,
                    'job_id' => $job->getId(),
                    'payload' => json_encode($job->getPayload()),
                    'metadata' => json_encode($job->getMetadata()),
                    'created_at' => time()
                ]);
            }
        });
    }

    private function getQueueDriver(string $queue): QueueDriver
    {
        if (!isset($this->queues[$queue])) {
            $this->queues[$queue] = $this->createQueueDriver($queue);
        }
        return $this->queues[$queue];
    }

    private function createQueueDriver(string $queue): QueueDriver
    {
        $config = $this->getQueueConfig($queue);
        return new QueueDriver($config, $this->security);
    }
}
