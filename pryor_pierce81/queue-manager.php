<?php

namespace App\Core\Queue;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Monitoring\MonitoringInterface;
use App\Core\Exception\QueueException;
use Psr\Log\LoggerInterface;

class QueueManager implements QueueManagerInterface
{
    private SecurityManagerInterface $security;
    private MonitoringInterface $monitor;
    private LoggerInterface $logger;
    private array $queues = [];
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        MonitoringInterface $monitor,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function pushJob(string $queue, Job $job): string
    {
        $jobId = $this->generateJobId();

        try {
            DB::beginTransaction();

            $this->security->validateContext('queue:push');
            $this->validateQueue($queue);
            $this->validateJob($job);

            $monitoringId = $this->monitor->startOperation([
                'type' => 'queue_push',
                'queue' => $queue,
                'job_id' => $jobId
            ]);

            $this->executeJobPush($queue, $job, $jobId);
            $this->verifyJobState($jobId);

            $this->logJobPush($jobId, $queue, $job);
            $this->monitor->stopOperation($monitoringId);

            DB::commit();
            return $jobId;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleQueueFailure($queue, 'push', $jobId, $e);
            throw new QueueException("Failed to push job to queue: {$queue}", 0, $e);
        }
    }

    public function processJobs(string $queue): void
    {
        $operationId = $this->generateOperationId();

        try {
            DB::beginTransaction();

            $this->security->validateContext('queue:process');
            $this->validateQueue($queue);

            $monitoringId = $this->monitor->startOperation([
                'type' => 'queue_process',
                'queue' => $queue
            ]);

            while ($job = $this->fetchNextJob($queue)) {
                $this->processJob($job);
            }

            $this->logQueueProcessing($operationId, $queue);
            $this->monitor->stopOperation($monitoringId);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleQueueFailure($queue, 'process', null, $e);
            throw new QueueException("Queue processing failed: {$queue}", 0, $e);
        }
    }

    private function executeJobPush(string $queue, Job $job, string $jobId): void
    {
        $job->setId($jobId);
        $job->setQueue($queue);
        $job->setState('pending');
        $job->setAttempts(0);

        DB::table('jobs')->insert([
            'id' => $jobId,
            'queue' => $queue,
            'payload' => serialize($job),
            'created_at' => now(),
            'available_at' => now()->addSeconds($job->getDelay())
        ]);

        $this->queues[$queue][] = $jobId;
    }

    private function processJob(Job $job): void
    {
        $jobId = $job->getId();

        try {
            $this->security->validateContext('job:process');
            
            $job->setState('processing');
            $job->incrementAttempts();

            $result = $job->execute();
            
            if ($result->isSuccessful()) {
                $this->markJobComplete($job);
            } else {
                $this->handleJobFailure($job, $result->getError());
            }

        } catch (\Exception $e) {
            $this->handleJobFailure($job, $e->getMessage());
            throw $e;
        }
    }

    private function validateQueue(string $queue): void
    {
        if (!isset($this->config['queues'][$queue])) {
            throw new QueueException("Invalid queue: {$queue}");
        }

        if ($this->isQueueFull($queue)) {
            throw new QueueException("Queue is full: {$queue}");
        }
    }

    private function validateJob(Job $job): void
    {
        if (!$job->isValid()) {
            throw new QueueException("Invalid job configuration");
        }

        if ($job->getPayloadSize() > $this->config['max_payload_size']) {
            throw new QueueException("Job payload exceeds maximum size");
        }
    }

    private function isQueueFull(string $queue): bool
    {
        $queueSize = count($this->queues[$queue] ?? []);
        return $queueSize >= $this->config['queues'][$queue]['max_size'];
    }

    private function fetchNextJob(string $queue): ?Job
    {
        $job = DB::table('jobs')
            ->where('queue', $queue)
            ->where('available_at', '<=', now())
            ->where('reserved_at', null)
            ->orderBy('created_at')
            ->first();

        if (!$job) {
            return null;
        }

        return unserialize($job->payload);
    }

    private function markJobComplete(Job $job): void
    {
        DB::table('jobs')
            ->where('id', $job->getId())
            ->delete();

        $this->logJobCompletion($job);
    }

    private function handleJobFailure(Job $job, string $error): void
    {
        $job->setState('failed');

        if ($job->getAttempts() < $job->getMaxAttempts()) {
            $this->requeueJob($job);
        } else {
            $this->moveToFailedQueue($job, $error);
        }
    }

    private function requeueJob(Job $job): void
    {
        $delay = $this->calculateBackoff($job);

        DB::table('jobs')
            ->where('id', $job->getId())
            ->update([
                'available_at' => now()->addSeconds($delay),
                'attempts' => $job->getAttempts()
            ]);
    }

    private function moveToFailedQueue(Job $job, string $error): void
    {
        DB::table('failed_jobs')->insert([
            'id' => $job->getId(),
            'queue' => $job->getQueue(),
            'payload' => serialize($job),
            'error' => $error,
            'failed_at' => now()
        ]);

        DB::table('jobs')
            ->where('id', $job->getId())
            ->delete();
    }

    private function calculateBackoff(Job $job): int
    {
        return min(
            $this->config['max_backoff'],
            $this->config['base_backoff'] * pow(2, $job->getAttempts())
        );
    }

    private function generateJobId(): string
    {
        return uniqid('job_', true);
    }

    private function generateOperationId(): string
    {
        return uniqid('queue_', true);
    }

    private function logJobPush(string $jobId, string $queue, Job $job): void
    {
        $this->logger->info('Job pushed to queue', [
            'job_id' => $jobId,
            'queue' => $queue,
            'job_type' => get_class($job),
            'timestamp' => microtime(true)
        ]);
    }

    private function logQueueProcessing(string $operationId, string $queue): void
    {
        $this->logger->info('Queue processing completed', [
            'operation_id' => $operationId,
            'queue' => $queue,
            'timestamp' => microtime(true)
        ]);
    }

    private function logJobCompletion(Job $job): void
    {
        $this->logger->info('Job completed', [
            'job_id' => $job->getId(),
            'queue' => $job->getQueue(),
            'attempts' => $job->getAttempts(),
            'timestamp' => microtime(true)
        ]);
    }

    private function handleQueueFailure(
        string $queue,
        string $operation,
        ?string $jobId,
        \Exception $e
    ): void {
        $this->logger->error('Queue operation failed', [
            'queue' => $queue,
            'operation' => $operation,
            'job_id' => $jobId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function getDefaultConfig(): array
    {
        return [
            'queues' => [
                'default' => ['max_size' => 10000],
                'high' => ['max_size' => 5000],
                'low' => ['max_size' => 20000]
            ],
            'max_payload_size' => 65536,
            'base_backoff' => 30,
            'max_backoff' => 3600,
            'retry_limit' => 3,
            'monitoring_interval' => 60
        ];
    }
}
