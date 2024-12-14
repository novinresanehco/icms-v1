<?php

namespace App\Core\Foundation;

/**
 * Core security manager handling critical CMS operations
 * with comprehensive protection and validation
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
     */
    public function executeCriticalOperation(
        CriticalOperation $operation, 
        SecurityContext $context
    ): OperationResult {
        // Start transaction for atomic operation
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($operation, $context);
            
            // Execute with monitoring
            $result = $this->executeWithProtection($operation, $context);
            
            // Verify result meets requirements
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

    /**
     * Validate operation meets all security requirements
     */
    private function validateOperation(
        CriticalOperation $operation,
        SecurityContext $context
    ): void {
        // Validate input
        $this->validator->validateInput(
            $operation->getData(),
            $operation->getValidationRules()
        );

        // Check permissions
        if (!$this->accessControl->hasPermission(
            $context, 
            $operation->getRequiredPermissions()
        )) {
            throw new UnauthorizedException();
        }

        // Verify rate limits
        if (!$this->accessControl->checkRateLimit(
            $context,
            $operation->getRateLimitKey()
        )) {
            throw new RateLimitException();
        }

        // Additional security checks
        $this->performSecurityChecks($operation, $context);
    }

    /**
     * Execute operation with comprehensive monitoring
     */
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
                throw new OperationException('Invalid result');
            }

            return $result;
            
        } catch (\Exception $e) {
            $monitor->recordFailure($e);
            throw $e;
        }
    }

    /**
     * Verify operation result meets all requirements
     */
    private function verifyResult(OperationResult $result): void {
        if (!$this->validator->verifyIntegrity($result)) {
            throw new IntegrityException('Result integrity check failed');
        }

        if (!$this->validator->verifyBusinessRules($result)) {
            throw new BusinessRuleException('Business rule validation failed');
        }
    }

    /**
     * Handle operation failures with full auditing
     */
    private function handleFailure(
        CriticalOperation $operation,
        SecurityContext $context,
        \Exception $e
    ): void {
        $this->auditLogger->logFailure(
            $operation,
            $context,
            $e,
            [
                'stack_trace' => $e->getTraceAsString(),
                'system_state' => $this->captureSystemState()
            ]
        );

        $this->notifyFailure($operation, $context, $e);
        $this->executeFailureRecovery($operation, $context, $e);
    }
}
