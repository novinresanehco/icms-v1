<?php

namespace App\Core\Security;

use App\Core\Contracts\SecurityManagerInterface;
use App\Core\Exceptions\{SecurityException, ValidationException};
use Illuminate\Support\Facades\{DB, Log};

class CoreSecurityManager implements SecurityManagerInterface
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;
    private MetricsCollector $metrics;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        AccessControl $accessControl,
        MetricsCollector $metrics
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->accessControl = $accessControl;
        $this->metrics = $metrics;
    }

    public function executeCriticalOperation(CriticalOperation $operation): OperationResult
    {
        DB::beginTransaction();
        $startTime = microtime(true);
        
        try {
            // Pre-execution validation
            $this->validateOperation($operation);
            
            // Execute with monitoring
            $result = $this->executeWithProtection($operation);
            
            // Verify result
            $this->verifyResult($result);
            
            DB::commit();
            $this->logSuccess($operation, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($operation, $e);
            throw new SecurityException(
                'Critical operation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            $this->recordMetrics($operation, microtime(true) - $startTime);
        }
    }

    private function validateOperation(CriticalOperation $operation): void
    {
        // Input validation
        $this->validator->validateInput(
            $operation->getData(),
            $operation->getValidationRules()
        );

        // Permission check 
        if (!$this->accessControl->hasPermission($operation->getRequiredPermissions())) {
            throw new SecurityException('Insufficient permissions');
        }

        // Rate limit check
        if (!$this->accessControl->checkRateLimit($operation->getRateLimitKey())) {
            throw new SecurityException('Rate limit exceeded');
        }
    }

    private function executeWithProtection(CriticalOperation $operation): OperationResult
    {
        $monitor = new OperationMonitor();

        return $monitor->execute(function() use ($operation) {
            return $operation->execute();
        });
    }

    private function verifyResult(OperationResult $result): void
    {
        // Verify data integrity
        if (!$this->validator->verifyIntegrity($result)) {
            throw new SecurityException('Result integrity check failed');
        }

        // Verify business rules
        if (!$this->validator->verifyBusinessRules($result)) {
            throw new SecurityException('Business rule validation failed');
        }
    }

    private function logSuccess(CriticalOperation $operation, OperationResult $result): void
    {
        $this->auditLogger->logSuccess(
            $operation,
            $result,
            [
                'execution_time' => $this->metrics->getLastOperationTime(),
                'resources_used' => $this->metrics->getResourceUsage()
            ]
        );
    }

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
    }

    private function recordMetrics(CriticalOperation $operation, float $executionTime): void
    {
        $this->metrics->record([
            'operation_type' => $operation->getType(),
            'execution_time' => $executionTime,
            'memory_peak' => memory_get_peak_usage(true),
            'timestamp' => microtime(true)
        ]);
    }
}
