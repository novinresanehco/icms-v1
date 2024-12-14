<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Core\Interfaces\{
    SecurityManagerInterface,
    ValidationInterface,
    AuditInterface
};
use App\Core\Exceptions\{
    SecurityException,
    ValidationException
};

class CoreSecurityManager implements SecurityManagerInterface
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private SecurityConfig $config;
    private MetricsCollector $metrics;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        SecurityConfig $config,
        MetricsCollector $metrics
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->config = $config;
        $this->metrics = $metrics;
    }

    /**
     * Executes a critical operation with comprehensive protection
     *
     * @throws SecurityException
     * @throws ValidationException
     */
    public function executeCriticalOperation(CriticalOperation $operation): OperationResult
    {
        $startTime = microtime(true);
        
        try {
            DB::beginTransaction();
            
            // Pre-execution validation
            $this->validateOperation($operation);
            
            // Execute with monitoring
            $result = $this->executeWithProtection($operation);
            
            // Verify result integrity
            $this->verifyResult($result);
            
            DB::commit();
            $this->logSuccess($operation, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($operation, $e);
            throw new SecurityException(
                'Critical operation failed: ' . $e->getMessage(),
                previous: $e
            );
        } finally {
            $this->recordMetrics($operation, microtime(true) - $startTime);
        }
    }

    /**
     * Validates all aspects of the operation before execution
     */
    private function validateOperation(CriticalOperation $operation): void
    {
        // Validate input data
        if (!$this->validator->validateInput($operation->getData())) {
            throw new ValidationException('Invalid operation input');
        }

        // Verify security constraints
        if (!$this->validator->checkSecurityConstraints($operation)) {
            throw new SecurityException('Security constraints not met');
        }

        // Additional validation steps
        $this->performAdditionalValidation($operation);
    }

    /**
     * Executes the operation with comprehensive monitoring
     */
    private function executeWithProtection(CriticalOperation $operation): OperationResult
    {
        $monitor = new OperationMonitor($operation);
        
        try {
            return $monitor->execute(function() use ($operation) {
                return $operation->execute();
            });
        } catch (\Exception $e) {
            $monitor->recordFailure($e);
            throw $e;
        }
    }

    /**
     * Verifies the integrity of the operation result
     */
    private function verifyResult(OperationResult $result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Operation result validation failed');
        }

        // Additional result verification
        $this->performResultValidation($result);
    }

    /**
     * Handles operation failures with comprehensive logging
     */
    private function handleFailure(CriticalOperation $operation, \Exception $e): void
    {
        $this->auditLogger->logFailure(
            $operation,
            $e,
            [
                'stack_trace' => $e->getTraceAsString(),
                'system_state' => $this->captureSystemState()
            ]
        );

        $this->metrics->incrementFailureCount(
            $operation->getType(),
            $e->getCode()
        );

        // Execute recovery procedures
        $this->executeFailureRecovery($operation, $e);
    }

    /**
     * Records comprehensive metrics for the operation
     */
    private function recordMetrics(CriticalOperation $operation, float $executionTime): void
    {
        $this->metrics->record([
            'operation_type' => $operation->getType(),
            'execution_time' => $executionTime,
            'memory_usage' => memory_get_peak_usage(true),
            'timestamp' => microtime(true)
        ]);
    }

    /**
     * Captures current system state for diagnostics
     */
    private function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'load_average' => sys_getloadavg(),
            'timestamp' => microtime(true)
        ];
    }
}
