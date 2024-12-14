<?php

namespace App\Core\Security;

class CoreSecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private EncryptionService $encryption; 
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger, 
        AccessControl $accessControl
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->accessControl = $accessControl;
    }

    /**
     * Executes a critical operation with comprehensive protection
     */
    public function executeCriticalOperation(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
        DB::beginTransaction();
        
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
            throw new UnauthorizedException();
        }

        // Rate limit verification
        if (!$this->accessControl->checkRateLimit($context, $operation->getRateLimitKey())) {
            $this->auditLogger->logRateLimitExceeded($context, $operation);
            throw new RateLimitException();
        }
    }

    private function executeWithProtection(
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

    private function verifyResult(OperationResult $result): void 
    {
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
        $this->auditLogger->logFailure($operation, $context, $e);
        $this->notifySecurityTeam($operation, $e);
    }

    private function logSuccess(
        CriticalOperation $operation,
        SecurityContext $context,
        OperationResult $result
    ): void {
        $this->auditLogger->logSuccess($operation, $context, $result);
    }
}
