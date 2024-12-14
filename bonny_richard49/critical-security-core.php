<?php
namespace App\Core\Security;

final class SecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;
    private SecurityConfig $config;
    private MetricsCollector $metrics;
    
    public function executeCriticalOperation(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
        DB::beginTransaction();
        
        try {
            $this->validateOperation($operation, $context);
            $result = $this->executeWithProtection($operation, $context);
            $this->verifyResult($result);
            
            DB::commit();
            $this->logSuccess($operation, $context, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($operation, $context, $e);
            throw new SecurityException('Critical operation failed', 0, $e);
        }
    }

    private function validateOperation(
        CriticalOperation $operation,
        SecurityContext $context
    ): void {
        // Input validation
        if (!$this->validator->validateInput($operation->getData(), $operation->getRules())) {
            throw new ValidationException('Invalid input data');
        }

        // Permission check
        if (!$this->accessControl->hasPermission($context, $operation->getPermissions())) {
            $this->auditLogger->logUnauthorizedAccess($context, $operation);
            throw new UnauthorizedException('Insufficient permissions');
        }

        // Rate limit check
        if (!$this->accessControl->checkRateLimit($context)) {
            throw new RateLimitException('Rate limit exceeded');
        }

        // Security validations
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
        // Integrity check
        if (!$this->validator->verifyIntegrity($result)) {
            throw new IntegrityException('Result integrity check failed');
        }

        // Business rules
        if (!$this->validator->verifyBusinessRules($result)) {
            throw new BusinessRuleException('Business rule validation failed');
        }
    }

    private function performSecurityChecks(
        CriticalOperation $operation,
        SecurityContext $context
    ): void {
        // Verify session
        if (!$this->validator->verifySessionSecurity($context)) {
            throw new SecurityException('Session security validation failed');
        }

        // Check patterns
        if ($this->detectSuspiciousActivity($context)) {
            throw new SecurityException('Suspicious activity detected');
        }

        // Additional requirements
        foreach ($operation->getSecurityRequirements() as $requirement) {
            if (!$this->validateSecurityRequirement($requirement)) {
                throw new SecurityException("Security requirement not met: {$requirement}");
            }
        }
    }
}