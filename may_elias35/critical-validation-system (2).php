<?php

namespace App\Core\Validation;

class CriticalValidator {
    private SecurityCore $security;
    private MonitoringSystem $monitor;
    private ValidationMetrics $metrics;

    public function validateOperation(Operation $op): ValidationResult {
        // Pre-operation validation
        $this->validatePreConditions($op);
        
        // Monitor execution
        $this->monitorExecution($op);
        
        // Validate results
        return $this->validateResults($op);
    }

    private function validatePreConditions(Operation $op): void {
        assert($this->security->isSystemSecure(), 'Security violation detected');
        assert($this->monitor->hasResources(), 'Insufficient resources');
        assert($this->validator->meetsRequirements(), 'Requirements not met');
    }

    private function monitorExecution(Operation $op): void {
        $metrics = [
            'start_time' => microtime(true),
            'memory_start' => memory_get_usage(),
            'cpu_start' => sys_getloadavg()[0]
        ];

        try {
            $result = $op->execute();
            $this->validateExecutionMetrics($metrics, $result);
        } catch (\Exception $e) {
            $this->handleExecutionFailure($e, $metrics);
            throw $e;
        }
    }

    private function validateResults(Operation $op): ValidationResult {
        assert($this->security->verifyIntegrity(), 'Integrity check failed');
        assert($this->monitor->checkThresholds(), 'Performance threshold exceeded');
        assert($this->metrics->areWithinLimits(), 'Metrics outside acceptable range');
        
        return new ValidationResult(true);
    }

    private function handleExecutionFailure(\Exception $e, array $metrics): void {
        $this->monitor->logFailure($e, $metrics);
        $this->security->handleSecurityEvent($e);
        $this->metrics->recordFailure($e);
    }
}
