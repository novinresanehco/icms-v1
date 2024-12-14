<?php

namespace App\Core\Queue;

use App\Core\Interfaces\QueueManagerInterface;
use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Monitoring\MonitoringService;
use App\Core\Protection\ProtectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class QueueManager implements QueueManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private MonitoringService $monitor;
    private ProtectionService $protection;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        MonitoringService $monitor, 
        ProtectionService $protection
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->monitor = $monitor;
        $this->protection = $protection;
    }

    public function processQueueItem(QueueItem $item): ProcessResult 
    {
        // Generate tracking ID
        $trackingId = $this->generateTrackingId();
        
        // Start monitoring
        $this->monitor->startTracking($trackingId);

        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateExecution($item);

            // Security check
            $this->security->validateOperation($item);

            // Create protection point
            $protectionId = $this->protection->createProtectionPoint();

            // Process item with monitoring
            $result = $this->executeWithMonitoring($item);

            // Validate result
            $this->validateResult($result);

            // Log success
            $this->logSuccess($trackingId, $result);

            DB::commit();

            return new ProcessResult(
                success: true,
                trackingId: $trackingId,
                result: $result
            );

        } catch (\Throwable $e) {
            // Rollback transaction
            DB::rollBack();

            // Log failure
            $this->logFailure($trackingId, $e);

            // Restore protection point if needed
            $this->protection->restoreFromPoint($protectionId);

            throw new QueueProcessingException(
                message: 'Queue processing failed: ' . $e->getMessage(),
                previous: $e
            );
        } finally {
            // Stop monitoring
            $this->monitor->stopTracking($trackingId);
            
            // Cleanup
            $this->cleanup($trackingId, $protectionId);
        }
    }

    private function validateExecution(QueueItem $item): void
    {
        if (!$this->validator->validateItem($item)) {
            throw new ValidationException('Queue item validation failed');
        }

        if (!$this->validator->checkExecutionConstraints($item)) {
            throw new ConstraintException('Execution constraints not met');
        }
    }

    private function executeWithMonitoring(QueueItem $item): mixed
    {
        return $this->monitor->track(
            'queue_processing',
            fn() => $this->processItem($item)
        );
    }

    private function processItem(QueueItem $item): mixed
    {
        // Validate state
        if (!$this->validator->validateState($item)) {
            throw new StateException('Invalid item state');
        }

        // Execute item processing
        $result = $item->process();

        // Validate processing result
        if (!$this->validator->validateProcessingResult($result)) {
            throw new ProcessingException('Invalid processing result');
        }

        return $result;
    }

    private function validateResult($result): void 
    {
        if (!$this->validator->validateOutput($result)) {
            throw new ValidationException('Result validation failed');
        }

        if (!$this->security->validateResult($result)) {
            throw new SecurityException('Result security validation failed');
        }
    }

    private function generateTrackingId(): string
    {
        return uniqid('queue_', true);
    }

    private function logSuccess(string $trackingId, $result): void
    {
        Log::info('Queue processing completed', [
            'tracking_id' => $trackingId,
            'result' => $result,
            'metrics' => $this->monitor->getMetrics($trackingId)
        ]);
    }

    private function logFailure(string $trackingId, \Throwable $e): void
    {
        Log::error('Queue processing failed', [
            'tracking_id' => $trackingId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'metrics' => $this->monitor->getMetrics($trackingId)
        ]);
    }

    private function cleanup(string $trackingId, string $protectionId): void
    {
        try {
            $this->protection->cleanupProtectionPoint($protectionId);
            $this->monitor->cleanupTracking($trackingId);
        } catch (\Exception $e) {
            Log::warning('Cleanup failed', [
                'tracking_id' => $trackingId,
                'protection_id' => $protectionId,
                'error' => $e->getMessage()
            ]);
        }
    }
}
