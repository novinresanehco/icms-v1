<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use App\Core\Exceptions\SecurityException;
use App\Core\Security\Interfaces\SecurityManagerInterface;

class CoreSecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private AccessControl $accessControl;
    private AuditLogger $auditLogger;
    private MetricsCollector $metrics;

    public function __construct(
        ValidationService $validator,
        AccessControl $accessControl,
        AuditLogger $auditLogger,
        MetricsCollector $metrics
    ) {
        $this->validator = $validator;
        $this->accessControl = $accessControl;
        $this->auditLogger = $auditLogger;
        $this->metrics = $metrics;
    }

    public function executeCriticalOperation(
        CriticalOperation $operation, 
        SecurityContext $context
    ): OperationResult {
        $startTime = microtime(true);
        DB::beginTransaction();

        try {
            // Pre-execution validation
            $this->validateOperation($operation, $context);
            
            // Execute with monitoring
            $result = $this->executeWithProtection($operation, $context);
            
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

        // Verify permissions
        if (!$this->accessControl->hasPermission(
            $context,
            $operation->getRequiredPermissions()
        )) {
            $this->auditLogger->logUnauthorizedAccess($context, $operation);
            throw new UnauthorizedException('Insufficient permissions');
        }

        // Check rate limits
        if (!$this->accessControl->checkRateLimit(
            $context,
            $operation->getRateLimitKey()
        )) {
            $this->auditLogger->logRateLimitExceeded($context, $operation);
            throw new RateLimitException('Rate limit exceeded');
        }

        // Additional security checks
        $this->performSecurityChecks($operation, $context);
    }

    private function executeWithProtection(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
        $monitor = new OperationMonitor($operation, $context);
        
        try {
            $result = $monitor->execute(function() use ($operation) {
                return $operation->execute();
            });

            if (!$result->isValid()) {
                throw new OperationException('Invalid operation result');
            }

            return $result;
            
        } catch (\Exception $e) {
            $monitor->recordFailure($e);
            throw $e;
        }
    }

    private function verifyResult(OperationResult $result): void {
        if (!$this->validator->verifyIntegrity($result)) {
            throw new IntegrityException('Result integrity check failed');
        }

        if (!$this->validator->verifyBusinessRules($result)) {
            throw new BusinessRuleException('Business rule validation failed');
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

        $this->notifyFailure($operation, $context, $e);
        $this->metrics->incrementFailureCount($operation->getType(), $e->getCode());
    }

    private function recordMetrics(
        CriticalOperation $operation,
        float $executionTime
    ): void {
        $this->metrics->record([
            'operation_type' => $operation->getType(),
            'execution_time' => $executionTime,
            'memory_usage' => memory_get_peak_usage(true),
            'timestamp' => microtime(true)
        ]);
    }

    private function logSuccess(
        CriticalOperation $operation,
        SecurityContext $context,
        OperationResult $result
    ): void {
        $this->auditLogger->logSuccess($context, $operation, $result);
    }
    
    private function performSecurityChecks(
        CriticalOperation $operation,
        SecurityContext $context
    ): void {
        foreach ($operation->getSecurityRequirements() as $requirement) {
            if (!$this->validateSecurityRequirement($requirement, $context)) {
                throw new SecurityException("Security requirement not met: {$requirement}");
            }
        }
    }
}
