<?php

namespace App\Core\Validation;

class CriticalValidationChain implements ValidationInterface
{
    private PatternValidator $patternValidator;
    private SecurityValidator $securityValidator;
    private QualityAnalyzer $qualityAnalyzer;
    private PerformanceMonitor $performanceMonitor;
    private AuditManager $auditManager;
    private AlertSystem $alertSystem;

    public function validateOperation(Operation $operation): ValidationResult
    {
        $validationId = $this->auditManager->startValidation($operation);
        DB::beginTransaction();

        try {
            // Critical Validation Chain
            $this->validatePattern($operation);      // Stage 1: Pattern Compliance
            $this->validateSecurity($operation);     // Stage 2: Security Validation
            $this->validateQuality($operation);      // Stage 3: Quality Check
            $this->validatePerformance($operation);  // Stage 4: Performance Check

            DB::commit();
            $this->auditManager->recordSuccess($validationId);
            return new ValidationResult(true);

        } catch (ValidationException $e) {
            DB::rollBack();
            $this->handleValidationFailure($validationId, $operation, $e);
            throw new CriticalValidationException(
                'Critical validation chain failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    private function validatePattern(Operation $operation): void
    {
        $result = $this->patternValidator->validate($operation, [
            'mode' => 'critical',
            'matching' => 'exact',
            'tolerance' => 0,
            'depth' => 'comprehensive'
        ]);

        if (!$result->isValid()) {
            throw new PatternViolationException($result->getViolations());
        }
    }

    private function validateSecurity(Operation $operation): void
    {
        $result = $this->securityValidator->validate($operation, [
            'level' => 'critical',
            'protocol' => 'strict',
            'scope' => 'comprehensive'
        ]);

        if (!$result->isValid()) {
            throw new SecurityViolationException($result->getViolations());
        }
    }

    private function validateQuality(Operation $operation): void
    {
        $result = $this->qualityAnalyzer->analyze($operation, [
            'standards' => 'maximum',
            'metrics' => 'all',
            'thresholds' => 'strict'
        ]);

        if (!$result->meetsStandards()) {
            throw new QualityViolationException($result->getViolations());
        }
    }

    private function validatePerformance(Operation $operation): void
    {
        $result = $this->performanceMonitor->analyze($operation, [
            'metrics' => 'comprehensive',
            'thresholds' => 'critical',
            'monitoring' => 'real_time'
        ]);

        if (!$result->meetsRequirements()) {
            throw new PerformanceViolationException($result->getViolations());
        }
    }

    private function handleValidationFailure(
        string $validationId, 
        Operation $operation, 
        ValidationException $e
    ): void {
        // Record detailed failure information
        $this->auditManager->recordFailure($validationId, [
            'operation' => $operation->getIdentifier(),
            'error' => $e->getMessage(),
            'stack_trace' => $e->getTraceAsString(),
            'system_state' => $this->captureSystemState(),
            'validation_context' => $operation->getContext()
        ]);

        // Trigger immediate escalation
        $this->alertSystem->triggerCriticalAlert([
            'type' => 'VALIDATION_FAILURE',
            'severity' => 'CRITICAL',
            'operation' => $operation->getIdentifier(),
            'error' => $e->getMessage(),
            'timestamp' => now(),
            'requires_immediate_action' => true
        ]);
    }

    private function captureSystemState(): array
    {
        return [
            'memory' => [
                'current' => memory_get_usage(true),
                'peak' => memory_get_peak_usage(true)
            ],
            'system' => [
                'load' => sys_getloadavg(),
                'connections' => DB::connection()->count(),
                'cache_status' => Cache::getStatus()
            ],
            'performance' => [
                'response_time' => PerformanceTracker::getAverageResponseTime(),
                'throughput' => PerformanceTracker::getCurrentThroughput(),
                'error_rate' => ErrorTracker::getCurrentRate()
            ],
            'resources' => [
                'cpu' => ResourceMonitor::getCpuUsage(),
                'io' => ResourceMonitor::getIoMetrics(),
                'network' => ResourceMonitor::getNetworkMetrics()
            ]
        ];
    }
}
