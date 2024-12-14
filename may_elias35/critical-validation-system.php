<?php

namespace App\Core\Validation;

class CriticalValidator
{
    private SecurityService $security;
    private PerformanceMonitor $performance;
    private AuditLogger $logger;
    private MetricsCollector $metrics;

    public function validateOperation(Operation $op): ValidationResult
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validatePreConditions($op);
            
            // Execute with monitoring
            $metrics = $this->executeWithMonitoring($op);
            
            // Validate results
            $this->validateResults($metrics);
            
            DB::commit();
            return ValidationResult::success();
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $op);
            throw new ValidationException('Operation failed validation', 0, $e);
        }
    }

    private function validatePreConditions(Operation $op): void
    {
        // Security validation
        assert($this->security->isSystemSecure(), 'Security violation detected');
        
        // Resource validation
        assert($this->performance->checkResources(), 'Insufficient resources');
        
        // State validation
        assert($this->validator->checkState(), 'Invalid system state');
    }

    private function executeWithMonitoring(Operation $op): array
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage();

        $result = $op->execute();

        return [
            'execution_time' => microtime(true) - $startTime,
            'memory_usage' => memory_get_usage() - $startMemory,
            'cpu_usage' => sys_getloadavg()[0],
            'result' => $result
        ];
    }

    private function validateResults(array $metrics): void
    {
        // Performance validation
        assert(
            $metrics['execution_time'] < CriticalMetrics::RESPONSE_TIME,
            'Performance threshold exceeded'
        );
        
        // Resource validation
        assert(
            $metrics['memory_usage'] < CriticalMetrics::MEMORY_LIMIT,
            'Memory limit exceeded'
        );
        
        // System validation
        assert(
            $metrics['cpu_usage'] < CriticalMetrics::CPU_LIMIT,
            'CPU threshold exceeded'
        );
    }

    private function handleFailure(\Exception $e, Operation $op): void
    {
        // Log failure
        $this->logger->critical('Operation failed', [
            'operation' => $op,
            'exception' => $e,
            'trace' => $e->getTraceAsString(),
            'metrics' => $this->metrics->getCurrentMetrics()
        ]);

        // Execute recovery procedures
        $this->executeRecovery($op);
        
        // Alert monitoring team
        $this->alertTeam($e, $op);
    }
}
