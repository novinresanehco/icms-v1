<?php

namespace App\Core\Queue;

class QueueManager implements QueueInterface
{
    private JobStore $store;
    private SecurityManager $security;
    private ValidationService $validator;
    private ProcessManager $process;
    private AuditLogger $logger;
    private MetricsCollector $metrics;

    public function __construct(
        JobStore $store,
        SecurityManager $security,
        ValidationService $validator,
        ProcessManager $process,
        AuditLogger $logger,
        MetricsCollector $metrics
    ) {
        $this->store = $store;
        $this->security = $security;
        $this->validator = $validator;
        $this->process = $process;
        $this->logger = $logger;
        $this->metrics = $metrics;
    }

    public function push(string $queue, Job $job): string
    {
        $jobId = uniqid('job_', true);

        try {
            $this->validateJob($job);
            $this->security->validateQueueAccess($queue);

            $secureJob = $this->secureJob($job);
            $this->storeJob($jobId, $queue, $secureJob);
            
            $this->logger->logJobPush($jobId, $queue);
            $this->metrics->recordJobPush($queue);

            return $jobId;

        } catch (\Exception $e) {
            $this->handlePushFailure($jobId, $queue, $e);
            throw new QueueException('Job push failed', 0, $e);
        }
    }

    public function process(string $queue): void
    {
        try {
            $this->security->validateProcessorAccess($queue);
            
            while ($job = $this->getNextJob($queue)) {
                $this->processJob($job);
            }

        } catch (\Exception $e) {
            $this->handleProcessorFailure($queue, $e);
            throw new ProcessorException('Queue processor failed', 0, $e);
        }
    }

    private function validateJob(Job $job): void
    {
        if (!$this->validator->validateJobPayload($job->getPayload())) {
            throw new ValidationException('Invalid job payload');
        }

        if (!$this->validator->validateJobHandler($job->getHandler())) {
            throw new ValidationException('Invalid job handler');
        }

        if ($this->security->isRestrictedJobType($job->getType())) {
            throw new SecurityException('Restricted job type');
        }
    }

    private function secureJob(Job $job): Job
    {
        $securePayload = $this->securePayload($job->getPayload());
        return $job->withPayload($securePayload);
    }

    private function securePayload(array $payload): array
    {
        $sensitiveFields = $this->security->getSensitiveFields();
        $securedPayload = [];

        foreach ($payload as $key => $value) {
            if (in_array($key, $sensitiveFields)) {
                $securedPayload[$key] = $this->security->encryptField($value);
            } else {
                $securedPayload[$key] = $value;
            }
        }

        $securedPayload['_hash'] = $this->generatePayloadHash($securedPayload);
        return $securedPayload;
    }

    private function storeJob(string $jobId, string $queue, Job $job): void
    {
        $jobData = [
            'id' => $jobId,
            'queue' => $queue,
            'type' => $job->getType(),
            'handler' => $job->getHandler(),
            'payload' => $job->getPayload(),
            'attempts' => 0,
            'created_at' => now(),
            'available_at' => now()
        ];

        if (!$this->store->store($jobId, $jobData)) {
            throw new StorageException('Failed to store job');
        }
    }

    private function getNextJob(string $queue): ?Job
    {
        if ($jobData = $this->store->getNext($queue)) {
            return $this->hydrateJob($jobData);
        }
        return null;
    }

    private function processJob(Job $job): void
    {
        $startTime = microtime(true);

        try {
            $this->security->validateJobExecution($job);
            
            $this->process->execute($job);
            $this->markJobComplete($job);
            
            $this->metrics->recordJobSuccess($job, microtime(true) - $startTime);
            $this->logger->logJobComplete($job->getId());

        } catch (TemporaryException $e) {
            $this->handleTemporaryFailure($job, $e);
        } catch (\Exception $e) {
            $this->handlePermanentFailure($job, $e);
        }
    }

    private function markJobComplete(Job $job): void
    {
        $this->store->delete($job->getId());
        $this->store->storeComplete([
            'job_id' => $job->getId(),
            'queue' => $job->getQueue(),
            'completed_at' => now(),
            'runtime' => $job->getRuntime()
        ]);
    }

    private function handleTemporaryFailure(Job $job, \Exception $e): void
    {
        $attempts = $job->getAttempts() + 1;
        
        if ($attempts >= config('queue.max_attempts')) {
            $this->handlePermanentFailure($job, $e);
            return;
        }

        $delay = $this->calculateBackoff($attempts);
        
        $this->store->markForRetry($job->getId(), [
            'attempts' => $attempts,
            'available_at' => now()->addSeconds($delay),
            'last_error' => $e->getMessage()
        ]);

        $this->logger->logJobRetry($job->getId(), $attempts, $delay);
        $this->metrics->recordJobRetry($job->getQueue());
    }

    private function handlePermanentFailure(Job $job, \Exception $e): void
    {
        $this->store->markFailed($job->getId(), [
            'error' => $e->getMessage(),
            'failed_at' => now()
        ]);

        $this->logger->logJobFailed($job->getId(), $e);
        $this->metrics->recordJobFailure($job->getQueue());

        if ($e instanceof SecurityException) {
            $this->security->handleSecurityIncident($job->getId(), $e);
        }
    }

    private function calculateBackoff(int $attempts): int
    {
        return min(
            pow(2, $attempts) * config('queue.backoff_base'),
            config('queue.max_backoff')
        );
    }

    private function generatePayloadHash(array $payload): string
    {
        return hash_hmac(
            'sha256',
            json_encode($payload),
            $this->security->getSecretKey()
        );
    }

    private function hydrateJob(array $data): Job
    {
        return new Job(
            $data['id'],
            $data['type'],
            $data['handler'],
            $data['payload'],
            $data['attempts']
        );
    }
}
