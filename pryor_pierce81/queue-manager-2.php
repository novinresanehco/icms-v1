<?php

namespace App\Core\Queue;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\QueueException;
use Psr\Log\LoggerInterface;

class QueueManager implements QueueManagerInterface 
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $config;
    private array $queues = [];

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function pushJob(Job $job, string $queue = null): string 
    {
        $jobId = $this->generateJobId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('queue:push', [
                'job_type' => $job->getType()
            ]);

            $this->validateJob($job);
            $queue = $queue ?? $this->getDefaultQueue($job);

            $this->validateQueue($queue);
            $this->processJobPush($jobId, $job, $queue);

            $this->logJobPush($jobId, $job, $queue);

            DB::commit();
            return $jobId;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleQueueFailure($jobId, $job, $e);
            throw new QueueException('Job push failed', 0, $e);
        }
    }

    public function processQueue(string $queue): void 
    {
        $processId = $this->generateProcessId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('queue:process', [
                'queue' => $queue
            ]);

            $this->validateQueue($queue);
            $this->validateQueueAccess($queue);

            $jobs = $this->fetchQueueJobs($queue);
            $this->processJobs($processId, $jobs, $queue);

            $this->logQueueProcessing($processId, $queue);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleProcessingFailure($processId, $queue, $e);
            throw new QueueException('Queue processing failed', 0, $e);
        }
    }

    private function validateJob(Job $job): void 
    {
        if (!$job->isValid()) {
            throw new QueueException('Invalid job structure');
        }

        if (!$this->isJobTypeAllowed($job->getType())) {
            throw new QueueException('Job type not allowed');
        }

        if (!$this->validateJobPayload($job)) {
            throw new QueueException('Invalid job payload');
        }
    }

    private function processJobPush(string $jobId, Job $job, string $queue): void 
    {
        $queueHandler = $this->getQueueHandler($queue);
        
        $job->setId($jobId);
        $job->setQueue($queue);
        $job->setState(JobState::PENDING);
        
        $queueHandler->push($job);
    }

    private function processJobs(string $processId, array $jobs, string $queue): void 
    {
        foreach ($jobs as $job) {
            try {
                $this->processJob($job, $queue);
            } catch (\Exception $e) {
                $this->handleJobFailure($processId, $job, $e);
                if ($this->config['fail_fast']) {
                    throw $e;
                }
            }
        }
    }

    private function processJob(Job $job, string $queue): void 
    {
        $handler = $this->getJobHandler($job);
        $context = $this->createJobContext($job, $queue);

        try {
            $job->setState(JobState::PROCESSING);
            $result = $handler->handle($job, $context);
            
            $this->validateJobResult($result);
            $job->setState(JobState::COMPLETED);
            
            $this->logJobCompletion($job);

        } catch (\Exception $e) {
            $job->setState(JobState::FAILED);
            $job->setError($e->getMessage());
            
            $this->handleJobError($job, $e);
            throw $e;
        }
    }

    private function handleQueueFailure(string $id, $job, \Exception $e): void 
    {
        $this->logger->error('Queue operation failed', [
            'job_id' => $id,
            'job_type' => $job->getType(),
            'error' => $e->getMessage()
        ]);

        if ($this->config['retry_failed']) {
            $this->queueForRetry($id, $job);
        }
    }

    private function getDefaultConfig(): array 
    {
        return [
            'default_queue' => 'default',
            'retry_failed' => true,
            'max_retries' => 3,
            'fail_fast' => false,
            'job_timeout' => 300,
            'batch_size' => 100,
            'allowed_job_types' => [
                'mail',
                'notification',
                'report',
                'import',
                'export'
            ]
        ];
    }
}
