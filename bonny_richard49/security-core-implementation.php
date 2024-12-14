<?php
namespace App\Core\Security;

class CriticalSecurityManager implements SecurityManagerInterface
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;
    private SecurityConfig $config;

    public function executeCriticalOperation(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
        DB::beginTransaction();
        $startTime = microtime(true);
        
        try {
            // Pre-execution validation
            $this->validateOperation($operation, $context);
            
            // Execute with monitoring
            $result = $this->executeWithProtection($operation, $context);
            
            // Verify result integrity
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
        // Input validation
        $this->validator->validateInput(
            $operation->getData(),
            $operation->getValidationRules()
        );

        // Permission check
        if (!$this->accessControl->hasPermission($context, $operation->getRequiredPermissions())) {
            $this->auditLogger->logUnauthorizedAccess($context, $operation);
            throw new UnauthorizedException('Insufficient permissions');
        }

        // Rate limit check
        if (!$this->accessControl->checkRateLimit($context)) {
            $this->auditLogger->logRateLimitExceeded($context);
            throw new RateLimitException('Rate limit exceeded');
        }

        // Additional security validations
        $this->performSecurityChecks($operation, $context);
    }

    private function executeWithProtection(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
        $monitor = new OperationMonitor($operation, $context);
        
        try {
            // Execute with monitoring
            $result = $monitor->execute(function() use ($operation) {
                return $operation->execute();
            });

            if (!$result->isValid()) {
                throw new OperationException('Operation produced invalid result');
            }

            return $result;
            
        } catch (\Exception $e) {
            $monitor->recordFailure($e);
            throw $e;
        }
    }

    private function verifyResult(OperationResult $result): void {
        // Data integrity check
        if (!$this->validator->verifyIntegrity($result)) {
            throw new IntegrityException('Result integrity validation failed');
        }

        // Business rules validation
        if (!$this->validator->verifyBusinessRules($result)) {
            throw new BusinessRuleException('Business rule validation failed');
        }

        // Additional result validations
        $this->performResultValidation($result);
    }

    private function handleFailure(
        CriticalOperation $operation,
        SecurityContext $context,
        \Exception $e
    ): void {
        // Log comprehensive failure details
        $this->auditLogger->logOperationFailure([
            'operation' => get_class($operation),
            'context' => $context->toArray(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'system_state' => $this->captureSystemState()
        ]);

        // Execute recovery procedures
        $this->executeRecoveryProcedures($operation, $e);

        // Notify relevant parties
        $this->notifySecurityTeam($operation, $context, $e);
    }

    private function performSecurityChecks(
        CriticalOperation $operation,
        SecurityContext $context
    ): void {
        // Verify session integrity
        if (!$this->validator->verifySessionIntegrity($context)) {
            throw new SecurityException('Session integrity check failed');
        }

        // Detect suspicious patterns
        if ($this->accessControl->detectSuspiciousActivity($context)) {
            throw new SecurityException('Suspicious activity detected');
        }

        // Verify security requirements
        foreach ($operation->getSecurityRequirements() as $requirement) {
            if (!$this->validateSecurityRequirement($requirement)) {
                throw new SecurityException("Security requirement not met: {$requirement}");
            }
        }
    }
}