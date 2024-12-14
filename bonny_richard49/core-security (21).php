<?php

namespace App\Core\Security;

/**
 * Core security system handling all critical security operations
 * with zero-tolerance for failures and complete audit logging.
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
     * Validates and executes a critical operation with complete protection
     *
     * @throws SecurityException If any security validation fails
     * @throws ValidationException If input validation fails
     */
    public function executeCriticalOperation(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
        // Begin transaction and monitoring
        DB::beginTransaction();
        $this->auditLogger->startOperation($operation, $context);
        
        try {
            // Comprehensive pre-execution validation
            $this->validateOperation($operation, $context);
            
            // Execute with full monitoring
            $result = $this->executeWithProtection($operation, $context);
            
            // Verify result integrity
            $this->verifyResult($result);
            
            // Commit and log success
            DB::commit();
            $this->auditLogger->logSuccess($operation, $context);
            
            return $result;
            
        } catch (\Exception $e) {
            // Rollback and handle failure
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
        // Validate all input data
        $this->validator->validateInput($operation->getData());

        // Verify permissions
        if (!$this->accessControl->hasPermission($context, $operation->getPermission())) {
            throw new UnauthorizedException();
        }

        // Check rate limits
        if (!$this->accessControl->checkRateLimit($context)) {
            throw new RateLimitException();
        }

        // Additional security checks
        $this->performSecurityChecks($operation, $context);
    }

    private function executeWithProtection(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
        // Create monitoring context
        $monitor = new OperationMonitor($context);
        
        try {
            // Execute with monitoring
            $result = $monitor->execute(function() use ($operation) {
                return $operation->execute();
            });

            // Validate result
            if (!$this->validateResult($result)) {
                throw new OperationException('Invalid operation result');
            }

            return $result;
            
        } catch (\Exception $e) {
            $monitor->recordFailure($e);
            throw $e;
        }
    }

    private function handleFailure(
        CriticalOperation $operation,
        SecurityContext $context,
        \Exception $e
    ): void {
        // Log comprehensive failure information
        $this->auditLogger->logFailure($operation, $context, $e);

        // Alert security team
        $this->alertSecurityTeam($operation, $e);

        // Execute recovery procedures if needed
        $this->executeFailureRecovery($operation);
    }

    private function performSecurityChecks(
        CriticalOperation $operation,
        SecurityContext $context
    ): void {
        // Verify additional security requirements
        foreach ($operation->getSecurityRequirements() as $requirement) {
            if (!$this->validateSecurityRequirement($requirement, $context)) {
                throw new SecurityException("Security requirement failed: $requirement");
            }
        }
    }
}
