<?php

namespace App\Core\Queue;

use App\Core\Security\SecurityManager;
use App\Core\Metrics\MetricsCollector;
use App\Core\Validation\ValidationService;
use App\Core\Logging\AuditLogger;

class QueueManager implements QueueInterface
{
    private SecurityManager $security;
    private MetricsCollector $metrics;
    private ValidationService $validator;
    private AuditLogger $logger;
    private array $queues = [];
    private array $workers = [];
    
    public function dispatch(string $queue, array $job, int $priority = self::PRIORITY_NORMAL): string
    {
        $jobId = $this->generateJobId();
        
        try {
            $this->validateDispatch($queue, $job, $priority);
            $this->security->validateAccess('queue.dispatch');

            $this->pushToQueue($jobId, $queue, $job, $priority);
            $this->processQueue($queue);

            return $jobId;

        } catch (\Exception $e) {
            $this->handleDispatchFailure($e, $queue, $job, $priority);
            throw $e;
        }
    }

    public function batch(string $batchId): QueueBatch
    {
        try {
            $this->security->validateAccess('queue.batch');
            return new QueueBatch($batchId, $this);
        } catch (\Exception $e) {
            $this->handleBatchFailure($e, $batchId);
            throw $e;
        }
    }

    public function process(string $queue): void
    {
        try {
            $this->security->validateAccess('queue.process');
            
            while ($job = $this->getNextJob($queue)) {
                $this->processJob($job);
            }

        } catch (\Exception $e) {
            $this->handleProcessFailure($e, $queue);
            throw $e;
        }
    }

    public function retry(string $jobId, array $options = []): bool
    {
        try {
            $this->security->validateAccess('queue.retry');
            
            if (!$job = $this->getFailedJob($jobId)) {
                throw new QueueException("Failed job not found: {$jobId}");
            }

            return $this->retryJob($job, $options);

        } catch (\Exception $e) {
            $this->handleRetryFailure($e, $jobId);
            throw $e;
        }
    }

    protected function pushToQueue(string $jobId, string $queue, array $job, int $priority): void
    {
        $queueJob = [
            'id' => $jobId,
            'queue' => $queue,
            'payload' => $job,
            'priority' => $priority,
            'attempts' => 0,
            'created_at' => time()
        ];

        DB::transaction(function() use ($queue, $queueJob) {
            $this->queues[$queue][] = $queueJob;
            $this->sortQueue($queue);
            $this->metrics->incrementCounter("queue.{$queue}.size");
        });

        $this->logger->info('Job queued', [
            'job_id' => $jobId,
            'queue' => $queue,
            'priority' => $priority
        ]);
    }

    protected function processJob(array $job): void
    {
        $startTime = microtime(true);
        
        try {
            $worker = $this->getWorker($job['queue']);
            $result = $worker->process($job['payload']);
            
            $this->handleJobSuccess($job, $result, $startTime);

        } catch (\Exception $e) {
            $this->handleJobFailure($job, $e, $startTime);
            
            if ($this->shouldRetryJob($job)) {
                $this->requeueJob($job);
            } else {
                $this->markJobAsFailed($job, $e);
            }
        }
    }

    protected function retryJob(array $job, array $options): bool
    {
        $job['attempts'] = 0;
        $job['last_attempt'] = null;
        
        if (isset($options['priority'])) {
            $job['priority'] = $options['priority'];
        }

        return $this->pushToQueue(
            $job['id'],
            $job['queue'],
            $job['payload'],
            $job['priority']
        );
    }

    protected function sortQueue(string $queue): void
    {
        usort($this->queues[$queue], function($a, $b) {
            if ($a['priority'] === $b['priority']) {
                return $a['created_at'] <=> $b['created_at'];
            }
            return $b['priority'] <=> $a['priority'];
        });
    }

    protected function validateDispatch(string $queue, array $job, int $priority): void
    {
        if (!$this->validator->validateQueue($queue)) {
            throw new QueueException('Invalid queue name');
        }

        if (!$this->validator->validateJob($job)) {
            throw new QueueException('Invalid job payload');
        }

        if (!$this->validator->validatePriority($priority)) {
            throw new QueueException('Invalid priority level');
        }
    }

    protected function handleJobSuccess(array $job, $result, float $startTime): void
    {
        $duration = microtime(true) - $startTime;

        $this->logger->info('Job completed', [
            'job_id' => $job['id'],
            'queue' => $job['queue'],
            'duration' => $duration
        ]);

        $this->metrics->record([
            'queue.job.duration' => $duration,
            'queue.job.success' => 1,
            "queue.{$job['queue']}.completed" => 1
        ]);
    }

    protected function handleJobFailure(array $job, \Exception $e, float $startTime): void
    {
        $duration = microtime(true) - $startTime;

        $this->logger->error('Job failed', [
            'job_id' => $job['id'],
            'queue' => $job['queue'],
            'error' => $e->getMessage(),
            'duration' => $duration
        ]);

        $this->metrics->record([
            'queue.job.duration' => $duration,
            'queue.job.failure' => 1,
            "queue.{$job['queue']}.failed" => 1
        ]);
    }

    protected function shouldRetryJob(array $job): bool
    {
        return $job['attempts'] < $this->getMaxAttempts($job['queue']);
    }

    protected function requeueJob(array $job): void
    {
        $job['attempts']++;
        $job['last_attempt'] = time();

        $this->pushToQueue(
            $job['id'],
            $job['queue'],
            $job['payload'],
            $job['priority']
        );
    }

    protected function markJobAsFailed(array $job, \Exception $e): void
    {
        DB::transaction(function() use ($job, $e) {
            DB::table('failed_jobs')->insert([
                'id' => $job['id'],
                'queue' => $job['queue'],
                'payload' => json_encode($job['payload']),
                'exception' => (string) $e,
                'failed_at' => time()
            ]);
        });
    }

    private function generateJobId(): string
    {
        return 'job_' . md5(uniqid(mt_rand(), true));
    }

    private function getWorker(string $queue): QueueWorker
    {
        if (!isset($this->workers[$queue])) {
            $this->workers[$queue] = new QueueWorker($queue);
        }
        return $this->workers[$queue];
    }
}
