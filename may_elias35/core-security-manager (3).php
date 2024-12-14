<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use App\Core\Interfaces\SecurityManagerInterface;
use App\Core\Services\{ValidationService, EncryptionService, AuditLogger};
use App\Core\Security\{AccessControl, SecurityConfig};
use App\Core\Monitoring\MetricsCollector;
use App\Core\Exceptions\{
    SecurityException,
    ValidationException,
    UnauthorizedException,
    RateLimitException
};

class SecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;
    private SecurityConfig $config;
    private MetricsCollector $metrics;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        AccessControl $accessControl,
        SecurityConfig $config,
        MetricsCollector $metrics
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->accessControl = $accessControl;
        $this->config = $config;
        $this->metrics = $metrics;
    }

    public function executeCriticalOperation(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
        $startTime = microtime(true);
        
        try {
            DB::beginTransaction();
            
            // Pre-execution validation
            $this->validateOperation($operation, $context);
            
            // Execute with monitoring
            $result = $this->executeWithMonitoring($operation, $context);
            
            // Verify result
            $this->verifyResult($result);
            
            DB::commit();
            $this->logSuccess($operation, $context, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($operation, $context, $e);
            
            throw new SecurityException(
                'Critical operation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            $this->recordMetrics($operation, microtime(true) - $startTime);
        }
    }

    private function validateOperation(
        CriticalOperation $operation,
        SecurityContext $context
    ): void {
        // Validate input data
        $this->validator->validateInput(
            $operation->getData(),
            $operation->getValidationRules()
        );

        // Check permissions
        if (!$this->accessControl->hasPermission(
            $context,
            $operation->getRequiredPermissions())
        ) {
            $this->auditLogger->logUnauthorizedAccess($context, $operation);
            throw new UnauthorizedException();
        }

        // Verify rate limits
        if (!$this->accessControl->checkRateLimit(
            $context,
            $operation->getRateLimitKey())
        ) {
            $this->auditLogger->logRateLimitExceeded($context, $operation);
            throw new RateLimitException();
        }
    }

    private function executeWithMonitoring(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
        $monitor = new OperationMonitor($operation, $context);
        
        try {
            return $monitor->execute(function() use ($operation) {
                return $operation->execute();
            });
        } catch (\Exception $e) {
            $monitor->recordFailure($e);
            throw $e;
        }
    }

    private function verifyResult(OperationResult $result): void {
        if (!$this->validator->verifyResultIntegrity($result)) {
            throw new SecurityException('Result integrity validation failed');
        }
    }

    private function handleFailure(
        CriticalOperation $operation,
        SecurityContext $context,
        \Exception $e
    ): void {
        $this->auditLogger->logOperationFailure($operation, $context, $e, [
            'stack_trace' => $e->getTraceAsString(),
            'input_data' => $operation->getData(),
            'system_state' => $this->captureSystemState()
        ]);

        $this->metrics->incrementFailureCount(
            $operation->getType(),
            $e->getCode()
        );

        $this->notifyFailure($operation, $context, $e);
    }

    private function recordMetrics(
        CriticalOperation $operation,
        float $executionTime
    ): void {
        $this->metrics->record([
            'operation_type' => $operation->getType(),
            'execution_time' => $executionTime,
            'memory_usage' => memory_get_peak_usage(true),
            'cpu_usage' => sys_getloadavg()[0],
            'timestamp' => microtime(true)
        ]);
    }

    private function logSuccess(
        CriticalOperation $operation,
        SecurityContext $context,
        OperationResult $result
    ): void {
        $this->auditLogger->logOperationSuccess($operation, $context, $result);
    }

    private function captureSystemState(): array {
        return [
            'memory' => memory_get_usage(true),
            'cpu' => sys_getloadavg(),
            'time' => microtime(true),
        ];
    }

    private function notifyFailure(
        CriticalOperation $operation,
        SecurityContext $context,
        \Exception $e
    ): void {
        // Implementation depends on notification system configuration
    }
}
