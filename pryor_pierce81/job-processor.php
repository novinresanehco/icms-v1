<?php

namespace App\Core\Queue;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\JobException;
use Psr\Log\LoggerInterface;

class JobProcessor implements JobProcessorInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function process(Job $job): JobResult
    {
        $operationId = $this->generateOperationId();

        try {
            DB::beginTransaction();

            $this->security->validateContext('job:execute');
            $this->validateJobState($job);

            $result = $this->executeJob($job);
            $this->validateJobResult($result);

            $this->logJobExecution($operationId, $job, $result);

            DB::commit();
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleJobFailure($operationId, $job, $e);
            throw new JobException("Job execution failed", 0, $e);
        }
    }

    private function executeJob(Job $job): JobResult
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        try {
            // Set up execution environment
            $this->prepareEnvironment($job);
            
            // Execute job
            $result = $job->handle();
            
            // Validate result
            if (!$result instanceof JobResult) {
                throw new JobException("Invalid job result type");
            }

            // Record metrics
            $this->recordJobMetrics($job, $startTime, $startMemory);

            return $result;

        } finally {
            // Cleanup
            $this->cleanupEnvironment();
        }
    }

    private function validateJobState(Job $job): void
    {
        if ($job->getState() !== 'pending') {
            throw new JobException("Invalid job state: {$job->getState()}");
        }

        if ($job->getAttempts() >= $this->config['max_attempts']) {
            throw new JobException("Maximum attempts exceeded");
        }
    }

    private function validateJobResult(JobResult $result): void
    {
        if (!$result->isValid()) {
            throw new JobException("Invalid job result");
        }
    }

    private function prepareEnvironment(Job $job): void
    {
        // Set memory limit
        ini_set('memory_limit', $this->config['memory_limit']);
        
        // Set execution time limit
        set_time_limit($this->config['time_limit']);
        
        // Initialize resources
        $this->initializeJobResources($job);
    }

    private function cleanupEnvironment(): void
    {
        // Reset memory limit
        ini_restore('memory_limit');
        
        // Reset time limit
        set_time_limit(30);
        
        // Cleanup resources
        $this->cleanupJobResources();
    }

    private function recordJobMetrics(
        Job $job,
        float $startTime,
        int $startMemory
    ): void {
        $endTime = microtime(true);
        $endMemory = memory_get_usage(true);

        $metrics = [
            'duration' => $endTime - $startTime,
            'memory_usage' => $endMemory - $startMemory,
            'peak_memory' => memory_get_peak_usage(true)
        ];

        $this->validateMetrics($metrics);
        $job->setMetrics($metrics);
    }

    private function validateMetrics(array $metrics): void
    {
        if ($metrics['duration'] > $this->config['max_duration']) {
            throw new JobException("Job exceeded maximum duration");
        }

        if ($metrics['memory_usage'] > $this->config['max_memory']) {
            throw new JobException("Job exceeded maximum memory usage");
        }
    }

    private function generateOperationId(): string
    {
        return uniqid('job_exec_', true);
    }

    private function logJobExecution(
        string $operationId,
        Job $job,
        JobResult $result
    ): void {
        $this->logger->info('Job executed', [
            'operation_id' => $operationId,
            'job_id' => $job->getId(),
            'success' => $result->isSuccessful(),
            'metrics' => $job->getMetrics(),
            'timestamp' => microtime(true)
        ]);
    }

    private function handleJobFailure(
        string $operationId,
        Job $job,
        \Exception $e
    ): void {
        $this->logger->error('Job execution failed', [
            'operation_id' => $operationId,
            'job_id' => $job->getId(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function getDefaultConfig(): array
    {
        return [
            'memory_limit' => '256M',
            'time_limit' => 300,
            'max_attempts' => 3,
            'max_duration' => 300,
            'max_memory' => 268435456,
            'retry_delay' => 60
        ];
    }
}
