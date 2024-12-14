<?php

namespace App\Core\Validation;

class CriticalValidator implements ValidationProtocol {
    private SecurityCore $security;
    private MonitoringSystem $monitor;
    private AuditLogger $logger;

    public function validateOperation(Operation $op): ValidationResult {
        // Pre-execution validation
        $this->validatePreConditions($op);
        
        // Monitor execution
        $metrics = $this->monitorExecution($op);
        
        // Validate results
        $this->validateResults($metrics);
        
        return new ValidationResult(true);
    }

    private function validatePreConditions(Operation $op): void {
        assert($this->security->isSystemSecure(), 'Security violation detected');
        assert($this->monitor->checkResources(), 'Insufficient resources');
        assert($this->security->validateState(), 'Invalid system state');
    }

    private function monitorExecution(Operation $op): array {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        try {
            $result = $op->execute();
            
            return [
                'execution_time' => microtime(true) - $startTime,
                'memory_usage' => memory_get_usage() - $startMemory,
                'cpu_usage' => sys_getloadavg()[0],
                'result' => $result
            ];
        } catch (\Exception $e) {
            $this->handleFailure($e, $op);
            throw $e;
        }
    }

    private function validateResults(array $metrics): void {
        assert($metrics['execution_time'] < CriticalMetrics::RESPONSE_TIME, 
               'Performance threshold exceeded');
               
        assert($metrics['memory_usage'] < PerformanceCore::MEMORY_THRESHOLD,
               'Memory threshold exceeded');
               
        assert($metrics['cpu_usage'] < PerformanceCore::CPU_LIMIT,
               'CPU threshold exceeded');
    }

    private function handleFailure(\Exception $e, Operation $op): void {
        $this->logger->logCritical('Operation failed', [
            'operation' => $op,
            'exception' => $e,
            'trace' => $e->getTraceAsString()
        ]);

        $this->monitor->alertCritical('Operation failure detected');
    }
}
