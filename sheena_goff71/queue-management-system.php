<?php

namespace App\Core\Queue;

class QueueManagementSystem implements QueueManagerInterface
{
    private JobProcessor $processor;
    private QueueValidator $validator;
    private PriorityManager $priority;
    private StateTracker $tracker;
    private EmergencyHandler $emergency;

    public function __construct(
        JobProcessor $processor,
        QueueValidator $validator,
        PriorityManager $priority,
        StateTracker $tracker,
        EmergencyHandler $emergency
    ) {
        $this->processor = $processor;
        $this->validator = $validator;
        $this->priority = $priority;
        $this->tracker = $tracker;
        $this->emergency = $emergency;
    }

    public function processJob(CriticalJob $job): ProcessingResult
    {
        $processingId = $this->initializeProcessing();
        DB::beginTransaction();

        try {
            // Validate job
            $validation = $this->validateJob($job);
            if (!$validation->isValid()) {
                throw new ValidationException('Job validation failed');
            }

            // Assign priority
            $priority = $this->priority->assignPriority($job);
            if ($priority->isCritical()) {
                $this->handleCriticalPriority($job, $priority);
            }

            // Process job
            $result = $this->executeJob($job, $priority, $processingId);

            // Verify processing
            $this->verifyProcessing($result);

            $this->tracker->recordProcessing($processingId, $result);
            DB::commit();

            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleProcessingFailure($processingId, $job, $e);
            throw $e;
        }
    }

    private function executeJob(
        CriticalJob $job,
        PriorityLevel $priority,
        string $processingId
    ): ProcessingResult {
        // Create execution context
        $context = $this->createExecutionContext($job, $priority);

        // Execute pre-processing checks
        $this->validator->validatePreProcessing($context);

        // Process job
        $result = $this->processor->process($job, $context);
        if (!$result->isSuccessful()) {
            throw new ProcessingException($result->getError());
        }

        // Execute post-processing validation
        $this->validator->validatePostProcessing($result);

        return new ProcessingResult(
            success: true,
            processingId: $processingId,
            output: $result->getOutput(),
            metrics: $this->collectProcessingMetrics($result)
        );
    }

    private function verifyProcessing(ProcessingResult $result): void
    {
        // Verify result integrity
        if (!$this->validator->verifyResultIntegrity($result)) {
            throw new IntegrityException('Processing result integrity check failed');
        }

        // Verify state consistency
        if (!$this->tracker->verifyStateConsistency($result)) {
            throw new StateException('Processing state consistency check failed');
        }

        // Verify output requirements
        if (!$this->validator->verifyOutputRequirements($result)) {
            throw new OutputException('Processing output requirements not met');
        }
    }

    private function handleProcessingFailure(
        string $processingId,
        CriticalJob $job,
        \Exception $e
    ): void {
        Log::critical('Job processing failed', [
            'processing_id' => $processingId,
            'job' => $job->toArray(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->emergency->handleProcessingFailure(
            $processingId,
            $job,
            $e
        );

        // Attempt job recovery if possible
        if ($job->isRecoverable()) {
            $this->attemptJobRecovery($processingId, $job);
        }
    }

    private function attemptJobRecovery(
        string $processingId,
        CriticalJob $job
    ): void {
        try {
            $recoveryPlan = $this->emergency->createRecoveryPlan($job);
            $this->emergency->executeRecovery($recoveryPlan);
        } catch (\Exception $recoveryError) {
            Log::emergency('Job recovery failed', [
                'processing_id' => $processingId,
                'error' => $recoveryError->getMessage()
            ]);
            $this->emergency->escalateFailure($processingId, $recoveryError);
        }
    }

    private function createExecutionContext(
        CriticalJob $job,
        PriorityLevel $priority
    ): ExecutionContext {
        return new ExecutionContext([
            'job_id' => $job->getId(),
            'priority' => $priority->getLevel(),
            'requirements' => $job->getRequirements(),
            'constraints' => $job->getConstraints(),
            'timestamp' => now()
        ]);
    }

    private function collectProcessingMetrics(ProcessingResult $result): array
    {
        return [
            'processing_time' => $result->getProcessingTime(),
            'memory_usage' => $result->getMemoryUsage(),
            'cpu_usage' => $result->getCpuUsage(),
            'io_operations' => $result->getIoOperations(),
            'queue_metrics' => $result->getQueueMetrics()
        ];
    }

    private function initializeProcessing(): string
    {
        return Str::uuid();
    }

    private function validateJob(CriticalJob $job): ValidationResult
    {
        $violations = [];

        // Validate job structure
        if (!$this->validator->validateStructure($job)) {
            $violations[] = new StructureViolation('Invalid job structure');
        }

        // Validate requirements
        if (!$this->validator->validateRequirements($job)) {
            $violations[] = new RequirementViolation('Invalid job requirements');
        }

        // Validate constraints
        if (!$this->validator->validateConstraints($job)) {
            $violations[] = new ConstraintViolation('Invalid job constraints');
        }

        return new ValidationResult(
            valid: empty($violations),
            violations: $violations
        );
    }

    private function handleCriticalPriority(
        CriticalJob $job,
        PriorityLevel $priority
    ): void {
        $this->emergency->initiateCriticalProtocol([
            'job' => $job,
            'priority' => $priority,
            'timestamp' => now()
        ]);
    }
}
