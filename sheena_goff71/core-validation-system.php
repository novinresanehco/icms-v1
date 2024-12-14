<?php

namespace App\Core\Validation;

use App\Core\Interfaces\ValidationInterface;
use App\Core\Monitoring\MonitoringService;
use App\Core\Protection\ProtectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ValidationManager implements ValidationInterface
{
    private MonitoringService $monitor;
    private ProtectionService $protection;
    private AuditService $audit;
    private array $validators;

    public function __construct(
        MonitoringService $monitor,
        ProtectionService $protection,
        AuditService $audit,
        array $validators = []
    ) {
        $this->monitor = $monitor;
        $this->protection = $protection;
        $this->audit = $audit;
        $this->validators = $validators;
    }

    public function validateOperation(Operation $operation): ValidationResult
    {
        $trackingId = $this->generateTrackingId();
        $this->monitor->startTracking($trackingId);

        DB::beginTransaction();

        try {
            // Pre-validation system check
            $this->validateSystemState();

            // Create protection point
            $protectionId = $this->protection->createProtectionPoint();

            // Execute validation chain
            $result = $this->executeValidationChain($operation);

            // Verify validation results
            $this->verifyValidationResult($result);

            // Log success
            $this->logValidationSuccess($trackingId, $result);

            DB::commit();

            return new ValidationResult(
                success: true,
                trackingId: $trackingId,
                details: $result
            );

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleValidationFailure($trackingId, $operation, $e);
            throw new ValidationException(
                message: 'Validation failed: ' . $e->getMessage(),
                previous: $e
            );
        } finally {
            $this->monitor->stopTracking($trackingId);
            $this->cleanup($trackingId, $protectionId ?? null);
        }
    }

    private function validateSystemState(): void
    {
        if (!$this->protection->verifySystemState()) {
            throw new SystemStateException('System state invalid for validation');
        }
    }

    private function executeValidationChain(Operation $operation): array
    {
        return $this->monitor->track('validation_chain', function() use ($operation) {
            $results = [];

            // Architecture compliance
            $results['architecture'] = $this->validateArchitecture($operation);

            // Security validation
            $results['security'] = $this->validateSecurity($operation);

            // Quality verification
            $results['quality'] = $this->validateQuality($operation);

            // Performance check
            $results['performance'] = $this->validatePerformance($operation);

            // Verify all validations passed
            foreach ($results as $phase => $result) {
                if (!$result->passed()) {
                    throw new ValidationPhaseException("Validation phase failed: {$phase}");
                }
            }

            return $results;
        });
    }

    private function validateArchitecture(Operation $operation): ValidationPhaseResult
    {
        $violations = [];

        foreach ($this->validators['architecture'] as $validator) {
            try {
                $validator->validate($operation);
            } catch (ValidationViolation $e) {
                $violations[] = $e;
            }
        }

        return new ValidationPhaseResult(
            phase: 'architecture',
            passed: empty($violations),
            violations: $violations
        );
    }

    private function validateSecurity(Operation $operation): ValidationPhaseResult
    {
        $violations = [];

        foreach ($this->validators['security'] as $validator) {
            try {
                $validator->validate($operation);
            } catch (ValidationViolation $e) {
                $violations[] = $e;
            }
        }

        return new ValidationPhaseResult(
            phase: 'security',
            passed: empty($violations),
            violations: $violations
        );
    }

    private function validateQuality(Operation $operation): ValidationPhaseResult
    {
        $violations = [];

        foreach ($this->validators['quality'] as $validator) {
            try {
                $validator->validate($operation);
            } catch (ValidationViolation $e) {
                $violations[] = $e;
            }
        }

        return new ValidationPhaseResult(
            phase: 'quality',
            passed: empty($violations),
            violations: $violations
        );
    }

    private function validatePerformance(Operation $operation): ValidationPhaseResult
    {
        $violations = [];

        foreach ($this->validators['performance'] as $validator) {
            try {
                $validator->validate($operation);
            } catch (ValidationViolation $e) {
                $violations[] = $e;
            }
        }

        return new ValidationPhaseResult(
            phase: 'performance',
            passed: empty($violations),
            violations: $violations
        );
    }

    private function verifyValidationResult(array $result): void
    {
        // Verify result integrity
        if (!$this->protection->verifyResultIntegrity($result)) {
            throw new IntegrityException('Validation result integrity check failed');
        }

        // Verify validation completeness
        if (!$this->verifyValidationCompleteness($result)) {
            throw new ValidationException('Incomplete validation result');
        }
    }

    private function verifyValidationCompleteness(array $result): bool
    {
        $requiredPhases = ['architecture', 'security', 'quality', 'performance'];
        
        foreach ($requiredPhases as $phase) {
            if (!isset($result[$phase])) {
                return false;
            }
        }

        return true;
    }

    private function handleValidationFailure(
        string $trackingId,
        Operation $operation,
        \Throwable $e
    ): void {
        // Log validation failure
        Log::error('Validation failure occurred', [
            'tracking_id' => $trackingId,
            'operation' => $operation->toArray(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        // Record validation incident
        $this->audit->recordValidationIncident([
            'tracking_id' => $trackingId,
            'type' => 'validation_failure',
            'details' => [
                'operation' => $operation->toArray(),
                'error' => $e->getMessage()
            ]
        ]);
    }

    private function logValidationSuccess(string $trackingId, array $result): void
    {
        $this->audit->recordValidationEvent([
            'tracking_id' => $trackingId,
            'type' => 'validation_complete',
            'status' => 'success',
            'details' => $result
        ]);
    }

    private function generateTrackingId(): string
    {
        return uniqid('val_', true);
    }

    private function cleanup(string $trackingId, ?string $protectionId): void
    {
        try {
            if ($protectionId) {
                $this->protection->cleanupProtectionPoint($protectionId);
            }
            $this->monitor->cleanupTracking($trackingId);
        } catch (\Exception $e) {
            Log::warning('Validation cleanup failed', [
                'tracking_id' => $trackingId,
                'error' => $e->getMessage()
            ]);
        }
    }
}
