<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\Log;
use App\Core\Interfaces\SecurityManagerInterface;
use App\Core\Security\Services\{
    ValidationService,
    EncryptionService,
    AuditLogger,
    AccessControl
};

/**
 * Core security manager handling all critical security operations
 * with comprehensive audit logging and failure protection.
 */
class SecurityManager implements SecurityManagerInterface 
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
     * Validates and executes a critical operation with comprehensive protection
     *
     * @param CriticalOperation $operation The operation to execute
     * @param SecurityContext $context Security context including user and permissions
     * @throws SecurityException If any security validation fails
     * @throws ValidationException If input validation fails
     * @return OperationResult The result of the operation
     */
    public function executeCriticalOperation(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
        // Start security transaction
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($operation, $context);
            
            // Execute operation with monitoring
            $result = $this->executeWithProtection($operation, $context);
            
            // Verify result integrity
            $this->verifyResult($result);
            
            // Commit and log success
            DB::commit();
            $this->logSuccess($operation, $context, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            // Rollback and handle failure
            DB::rollBack();
            $this->handleFailure($operation, $context, $e);
            throw new SecurityException('Critical operation failed', 0, $e);
        }
    }

    /**
     * Validates all aspects of the operation before execution
     */
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
        if (!$this->accessControl->hasPermission($context, $operation->getRequiredPermissions())) {
            $this->auditLogger->logUnauthorizedAccess($context, $operation);
            throw new UnauthorizedException('Insufficient permissions');
        }

        // Verify rate limits
        if (!$this->accessControl->checkRateLimit($context, $operation->getRateLimitKey())) {
            $this->auditLogger->logRateLimitExceeded($context, $operation);
            throw new RateLimitException('Rate limit exceeded');
        }
    }

    /**
     * Executes the operation with comprehensive monitoring
     */
    private function executeWithProtection(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
        // Create monitoring context
        $monitor = new OperationMonitor($operation, $context);
        
        try {
            // Execute with monitoring
            $result = $monitor->execute(function() use ($operation) {
                return $operation->execute();
            });

            // Validate result
            if (!$result->isValid()) {
                throw new OperationException('Invalid operation result');
            }

            return $result;
            
        } catch (\Exception $e) {
            $monitor->recordFailure($e);
            throw $e;
        }
    }

    /**
     * Verifies the integrity of the operation result
     */
    private function verifyResult(OperationResult $result): void {
        // Verify data integrity
        if (!$this->validator->verifyIntegrity($result)) {
            throw new IntegrityException('Result integrity check failed');
        }

        // Verify business rules
        if (!$this->validator->verifyBusinessRules($result)) {
            throw new BusinessRuleException('Business rule validation failed');
        }
    }

    /**
     * Handles operation failures with comprehensive logging
     */
    private function handleFailure(
        CriticalOperation $operation,
        SecurityContext $context,
        \Exception $e
    ): void {
        // Log detailed failure information
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

        // Notify security team
        $this->notifySecurityTeam($operation, $context, $e);
    }
}
