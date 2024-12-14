<?php

namespace App\Core\Jobs;

use Illuminate\Support\Facades\{Queue, DB, Cache};
use App\Core\Security\SecurityContext;
use App\Core\Services\{ValidationService, MonitoringService, AuditService};
use App\Core\Exceptions\{JobException, SecurityException};

class JobProcessor implements JobProcessorInterface
{
    private ValidationService $validator;
    private MonitoringService $monitor;
    private AuditService $audit;
    private array $config;

    public function __construct(
        ValidationService $validator,
        MonitoringService $monitor,
        AuditService $audit
    ) {
        $this->validator = $validator;
        $this->monitor = $monitor;
        $this->audit = $audit;
        $this->config = config('jobs');
    }

    public function dispatch(Job $job, SecurityContext $context): string
    {
        try {
            // Validate job
            $this->validateJob($job);

            // Process dispatch
            return DB::transaction(function() use ($job, $context) {
                // Create execution context
                $executionContext = $this->createExecutionContext($job, $context);

                // Apply security constraints
                $this->applySecurityConstraints($job, $context);

                // Prepare job for queuing
                $preparedJob = $this->prepareJob($job, $executionContext);

                // Queue job
                $jobId = $this->queueJob($preparedJob);

                // Start monitoring
                $this->startJobMonitoring($jobId);

                // Log dispatch
                $this->audit->logJobDispatch($job, $context);

                return $jobId;
            });

        } catch (\Exception $e) {
            $this->handleDispatchFailure($e, $job, $context);
            throw new JobException('Job dispatch failed: ' . $e->getMessage());
        }
    }

    public function monitor(string $jobId, SecurityContext $context): JobStatus
    {
        try {
            // Validate job ID
            $this->validateJobId($jobId);

            // Verify monitoring permission
            $this->verifyMonitoringPermission($jobId, $context);

            // Get job status
            $status = $this->getJobStatus($jobId);

            // Enhance with metrics
            $this->enhanceStatusWithMetrics($status);

            // Log monitoring
            $this->audit->logJobMonitoring($jobId, $context);

            return $status;

        } catch (\Exception $e) {
            $this->handleMonitoringFailure($e, $jobId, $context);
            throw new JobException('Job monitoring failed: ' . $e->getMessage());
        }
    }

    public function cancel(string $jobId, SecurityContext $context): bool
    {
        return DB::transaction(function() use ($jobId, $context) {
            try {
                // Validate cancellation request
                $this->validateCancellation($jobId);

                // Verify cancellation permission
                $this->verifyCancellationPermission($jobId, $context);

                // Execute cancellation
                $success = $this->executeCancellation($jobId);

                // Clean up resources
                $this->cleanupJobResources($jobId);

                // Log cancellation
                $this->audit->logJobCancellation($jobId, $context);

                return $success;

            } catch (\Exception $e) {
                $this->handleCancellationFailure($e, $jobId, $context);
                throw new JobException('Job cancellation failed: ' . $e->getMessage());
            }
        });
    }

    private function validateJob(Job $job): void
    {
        if (!$this->validator->validateJob($job)) {
            throw new JobException('Invalid job configuration');
        }
    }

    private function createExecutionContext(Job $job, SecurityContext $context): ExecutionContext
    {
        return new ExecutionContext([
            'job_id' => uniqid('job_', true),
            'security_context' => $context->toArray(),
            'job_config' => $job->getConfig(),
            'timestamp' => now()
        ]);
    }

    private function applySecurityConstraints(Job $job, SecurityContext $context): void
    {
        // Verify execution permissions
        if (!$this->hasExecutionPermission($job, $context)) {
            throw new SecurityException('Job execution permission denied');
        }

        // Apply resource constraints
        $this->applyResourceConstraints($job);

        // Set security context
        $job->setSecurityContext($context);
    }

    private function prepareJob(Job $job, ExecutionContext $context): Job
    {
        // Set execution context
        $job->setExecutionContext($context);

        // Apply middleware
        $job = $this->applyJobMiddleware($job);

        // Set up monitoring
        $job->setMonitoring($this->config['monitoring']);

        return $job;
    }

    private function queueJob(Job $job): string
    {
        $queue = $this->determineQueue($job);
        return Queue::pushOn($queue, $job);
    }

    private function startJobMonitoring(string $jobId): void
    {
        $this->monitor->startJobMonitoring($jobId, [
            'metrics' => $this->config['monitoring_metrics'],
            'alerts' => $this->config['monitoring_alerts'],
            'thresholds' => $this->config['monitoring_thresholds']
        ]);
    }

    private function validateJobId(string $jobId): void
    {
        if (!$this->validator->validateJobId($jobId)) {
            throw new JobException('Invalid job ID');
        }
    }

    private function verifyMonitoringPermission(string $jobId, SecurityContext $context): void
    {
        if (!$this->hasMonitoringPermission($jobId, $context)) {
            throw new SecurityException('Job monitoring permission denied');
        }
    }

    private function getJobStatus(string $jobId): JobStatus
    {
        $status = new JobStatus([
            'job_id' => $jobId,
            'state' => $this->getCurrentState($jobId),
            'progress' => $this->getJobProgress($jobId),
            'metrics' => $this->getJobMetrics($jobId),
            'timestamp' => now()
        ]);

        return $status;
    }

    private function enhanceStatusWithMetrics(JobStatus $status): void
    {
        $metrics = $this->monitor->getJobMetrics($status->getJobId());
        $status->setMetrics($metrics);
    }

    private function validateCancellation(string $jobId): void
    {
        if (!$this->isJobCancellable($jobId)) {
            throw new JobException('Job cannot be cancelled');
        }
    }

    private function verifyCancellationPermission(string $jobId, SecurityContext $context): void
    {
        if (!$this->hasCancellationPermission($jobId, $context)) {
            throw new SecurityException('Job cancellation permission denied');
        }
    }

    private function executeCancellation(string $jobId): bool
    {
        $job = $this->getJob($jobId);
        return $job->cancel();
    }

    private function cleanupJobResources(string $jobId): void
    {
        // Remove from queue
        Queue::deleteJob($jobId);

        // Clear monitoring
        $this->monitor->stopJobMonitoring($jobId);

        // Clear cache
        Cache::forget("job:$jobId");
    }

    private function handleDispatchFailure(\Exception $e, Job $job, SecurityContext $context): void
    {
        $this->audit->logJobFailure('dispatch', $job, $context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function handleMonitoringFailure(\Exception $e, string $jobId, SecurityContext $context): void
    {
        $this->audit->logJobFailure('monitoring', $jobId, $context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function handleCancellationFailure(\Exception $e, string $jobId, SecurityContext $context): void
    {
        $this->audit->logJobFailure('cancellation', $jobId, $context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
