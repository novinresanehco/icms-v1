<?php

namespace App\Core\Security;

class SecurityManager implements SecurityInterface 
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;
    private SecurityConfig $config;
    private MetricsCollector $metrics;

    public function validateOperation(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
        DB::beginTransaction();
        $startTime = microtime(true);
        
        try {
            $this->validatePreExecution($operation, $context);
            
            $result = $this->executeWithProtection($operation, $context);
            
            $this->verifyResult($result);
            
            DB::commit();
            $this->logSuccess($operation, $context, $result);
            
            return $result;
            
        } catch (Exception $e) {
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

    private function validatePreExecution(
        CriticalOperation $operation,
        SecurityContext $context
    ): void {
        $this->validator->validateInput(
            $operation->getData(),
            $operation->getValidationRules()
        );

        if (!$this->accessControl->hasPermission($context, $operation->getRequiredPermissions())) {
            $this->auditLogger->logUnauthorizedAccess($context, $operation);
            throw new UnauthorizedException('Insufficient permissions');
        }

        if (!$this->accessControl->checkRateLimit($context, $operation->getRateLimitKey())) {
            $this->auditLogger->logRateLimitExceeded($context, $operation);
            throw new RateLimitException('Rate limit exceeded');
        }

        foreach ($operation->getSecurityRequirements() as $requirement) {
            if (!$this->validateSecurityRequirement($requirement, $context)) {
                throw new SecurityException("Security requirement not met: {$requirement}");
            }
        }
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
            
        } catch (Exception $e) {
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

        $this->performResultValidation($result);
    }

    private function handleFailure(
        CriticalOperation $operation,
        SecurityContext $context,
        Exception $e
    ): void {
        $this->auditLogger->logOperationFailure(
            $operation,
            $context,
            $e,
            [
                'stack_trace' => $e->getTraceAsString(),
                'input_data' => $operation->getData(),
                'system_state' => $this->captureSystemState()
            ]
        );

        $this->notifyFailure($operation, $context, $e);

        $this->metrics->incrementFailureCount(
            $operation->getType(),
            $e->getCode()
        );

        $this->executeFailureRecovery($operation, $context, $e);
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

    private function performResultValidation(OperationResult $result): void {
        $validator = new ResultValidator($this->config);
        $validator->validateStructure($result);
        $validator->validateDataIntegrity($result);
        $validator->validateSecurityCompliance($result);
        $validator->validatePerformanceMetrics($result);
    }

    private function notifyFailure(
        CriticalOperation $operation,
        SecurityContext $context,
        Exception $e
    ): void {
        $notification = new SecurityNotification(
            $operation,
            $context,
            $e,
            SecurityLevel::CRITICAL
        );
        
        $this->notificationService->send($notification);
    }

    private function captureSystemState(): array
    {
        return [
            'memory' => memory_get_usage(true),
            'cpu' => sys_getloadavg(),
            'connections' => DB::getConnectionsStatus(),
            'cache' => Cache::getStatus(),
            'queue' => Queue::getStatus()
        ];
    }

    private function executeFailureRecovery(
        CriticalOperation $operation,
        SecurityContext $context,
        Exception $e
    ): void {
        $recovery = new FailureRecovery(
            $operation,
            $context,
            $e,
            $this->config->getRecoveryStrategies()
        );
        
        $recovery->execute();
    }
}
