<?php

namespace App\Core\Queue;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Monitoring\QueueMonitorInterface;
use App\Core\Exception\{QueueException, SecurityException};
use Psr\Log\LoggerInterface;

class QueueManager implements QueueManagerInterface
{
    private $driver;
    private SecurityManagerInterface $security;
    private QueueMonitorInterface $monitor;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        QueueDriverInterface $driver,
        SecurityManagerInterface $security,
        QueueMonitorInterface $monitor,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->driver = $driver;
        $this->security = $security;
        $this->monitor = $monitor;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function push(string $queue, array $data, array $context = []): string
    {
        $jobId = $this->generateJobId();
        $this->monitor->startJob($jobId, $queue, $data);

        try {
            $this->security->validateAccess('queue:push', $queue, $context);
            $this->validateJob($data);

            $job = $this->prepareJob($jobId, $data);
            $this->driver->push($queue, $job);

            $this->monitor->jobPushed($jobId);
            return $jobId;

        } catch (\Exception $e) {
            $this->monitor->jobFailed($jobId, $e);
            $this->logger->error('Queue push failed', [
                'queue' => $queue,
                'job_id' => $jobId,
                'error' => $e->getMessage()
            ]);
            throw new QueueException('Queue push failed', 0, $e);
        }
    }

    public function pop(string $queue, array $context = []): ?array
    {
        try {
            $this->security->validateAccess('queue:pop', $queue, $context);

            $job = $this->driver->pop($queue);
            if (!$job) {
                return null;
            }

            $this->validateJob($job);
            $this->monitor->jobPopped($job['id']);

            return $job;

        } catch (\Exception $e) {
            $this->logger->error('Queue pop failed', [
                'queue' => $queue,
                'error' => $e->getMessage()
            ]);
            throw new QueueException('Queue pop failed', 0, $e);
        }
    }

    public function acknowledge(string $queue, string $jobId, array $context = []): void
    {
        try {
            $this->security->validateAccess('queue:acknowledge', $queue, $context);
            
            $this->driver->acknowledge($queue, $jobId);
            $this->monitor->jobCompleted($jobId);

        } catch (\Exception $e) {
            $this->logger->error('Job acknowledge failed', [
                'queue' => $queue,
                'job_id' => $jobId,
                'error' => $e->getMessage()
            ]);
            throw new QueueException('Job acknowledge failed', 0, $e);
        }
    }

    public function fail(string $queue, string $jobId, \Exception $error, array $context = []): void
    {
        try {
            $this->security->validateAccess('queue:fail', $queue, $context);

            $this->driver->fail($queue, $jobId);
            $this->monitor->jobFailed($jobId, $error);

        } catch (\Exception $e) {
            $this->logger->error('Job fail marking failed', [
                'queue' => $queue,
                'job_id' => $jobId,
                'error' => $e->getMessage()
            ]);
            throw new QueueException('Job fail marking failed', 0, $e);
        }
    }

    private function generateJobId(): string
    {
        return uniqid('job_', true);
    }

    private function validateJob(array $data): void
    {
        if (!isset($data['type'])) {
            throw new QueueException('Job type not specified');
        }

        if (strlen(json_encode($data)) > $this->config['max_job_size']) {
            throw new QueueException('Job size exceeds limit');
        }
    }

    private function prepareJob(string $jobId, array $data): array
    {
        return [
            'id' => $jobId,
            'data' => $data,
            'attempts' => 0,
            'created_at' => time()
        ];
    }

    private function getDefaultConfig(): array
    {
        return [
            'max_job_size' => 65536,
            'max_attempts' => 3,
            'retry_delay' => 60
        ];
    }
}
