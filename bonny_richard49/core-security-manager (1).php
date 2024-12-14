<?php

namespace App\Core\Security;

/**
 * Core security manager for CMS with comprehensive protection and validation
 */
class CoreSecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;
    private SecurityConfig $config;
    
    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        AccessControl $accessControl,
        SecurityConfig $config
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->accessControl = $accessControl;
        $this->config = $config;
    }

    /**
     * Execute critical operation with full protection
     * 
     * @throws SecurityException
     */
    public function executeCriticalOperation(
        CriticalOperation $operation, 
        SecurityContext $context
    ): OperationResult {
        DB::beginTransaction();
        
        try {
            // Comprehensive pre-execution validation
            $this->validateOperation($operation, $context);
            
            // Execute with protection
            $result = $this->executeWithProtection($operation, $context);
            
            // Verify result integrity
            $this->verifyResult($result);
            
            DB::commit();
            $this->auditLogger->logSuccess($operation, $context);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $operation, $context);
            throw $e;
        }
    }

    /**
     * Validates operation before execution
     */
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
        if (!$this->accessControl->hasPermission(
            $context,
            $operation->getRequiredPermissions())
        ) {
            throw new UnauthorizedException();
        }

        // Rate limit verification  
        if (!$this->accessControl->checkRateLimit($context)) {
            throw new RateLimitException();
        }

        // Additional security checks
        $this->performSecurityChecks($operation, $context);
    }

    /**
     * Executes operation with monitoring
     */
    private function executeWithProtection(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
        // Create monitoring context
        $monitor = new OperationMonitor($operation, $context);
        
        try {
            // Execute with monitoring
            return $monitor->execute(function() use ($operation) {
                return $operation->execute();
            });
        } catch (\Exception $e) {
            $monitor->recordFailure($e);
            throw $e;
        }
    }

    /**
     * Verifies operation result integrity
     */
    private function verifyResult(OperationResult $result): void {
        if (!$this->validator->verifyIntegrity($result)) {
            throw new IntegrityException();
        }
    }

    /**
     * Handles operation failures with logging
     */
    private function handleFailure(
        \Exception $e,
        CriticalOperation $operation,
        SecurityContext $context
    ): void {
        $this->auditLogger->logFailure($e, $operation, $context);
        $this->notifyFailure($operation, $context, $e);
    }
}
