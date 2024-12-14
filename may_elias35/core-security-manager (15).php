<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use App\Core\Interfaces\{
    SecurityManagerInterface,
    ValidationServiceInterface,
    AuditLoggerInterface
};
use App\Core\Exception\{
    SecurityException,
    ValidationException,
    UnauthorizedException
};

/**
 * Core security manager handling critical system security operations
 * with comprehensive audit logging and failure protection.
 */
class CoreSecurityManager implements SecurityManagerInterface 
{
    private ValidationServiceInterface $validator;
    private EncryptionService $encryption;
    private AuditLoggerInterface $auditLogger;
    private AccessControl $accessControl;
    private SecurityConfig $config;

    public function __construct(
        ValidationServiceInterface $validator,
        EncryptionService $encryption,
        AuditLoggerInterface $auditLogger,
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
     * Validates and executes a critical operation with comprehensive protection
     */
    public function executeCriticalOperation(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
        DB::beginTransaction();
        $operationId = $this->generateOperationId();
        
        try {
            // Pre-execution validation
            $this->validateOperation($operation, $context);
            
            // Execute operation with monitoring
            $result = $this->executeWithProtection($operation, $context, $operationId);
            
            // Verify result integrity
            $this->verifyResult($result, $operationId);
            
            // Log success and commit
            $this->auditLogger->logSuccess($operation, $context, $operationId);
            DB::commit();
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($operation, $context, $e, $operationId);
            throw new SecurityException(
                'Critical operation failed: ' . $e->getMessage(),
                previous: $e
            );
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
        if (!$this->accessControl->hasPermission(
            $context->getUser(), 
            $operation->getRequiredPermissions()
        )) {
            $this->auditLogger->logUnauthorizedAccess($context);
            throw new UnauthorizedException();
        }

        // Verify rate limits
        if (!$this->accessControl->checkRateLimit($context->getUser())) {
            throw new RateLimitException();
        }

        // Additional security checks
        $this->runSecurityChecks($operation, $context);
    }

    /**
     * Executes the operation with comprehensive monitoring
     */
    private function executeWithProtection(
        CriticalOperation $operation,
        SecurityContext $context,
        string $operationId
    ): OperationResult {
        $monitor = new OperationMonitor($operation, $context, $operationId);
        
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

    /**
     * Verifies the integrity of the operation result
     */
    private function verifyResult(OperationResult $result, string $operationId): void {
        if (!$this->validator->verifyIntegrity($result->getData())) {
            throw new IntegrityException('Result integrity check failed');
        }

        if (!$this->validator->verifyBusinessRules($result)) {
            throw new ValidationException('Business rule validation failed');
        }

        $this->auditLogger->logVerification($result, $operationId);
    }

    /**
     * Handles operation failures with comprehensive logging
     */
    private function handleFailure(
        CriticalOperation $operation,
        SecurityContext $context,
        \Exception $e,
        string $operationId
    ): void {
        $this->auditLogger->logFailure(
            $operation,
            $context,
            $e,
            $operationId,
            $this->captureSystemState()
        );

        // Execute emergency protocols if needed
        if ($this->isEmergencyProtocolRequired($e)) {
            $this->executeEmergencyProtocol($operation, $context, $e);
        }
    }

    /**
     * Generates a unique operation identifier
     */
    private function generateOperationId(): string {
        return uniqid('op_', true);
    }

    /**
     * Captures current system state for diagnostics
     */
    private function captureSystemState(): array {
        return [
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'cpu_usage' => sys_getloadavg()[0],
            'timestamp' => microtime(true)
        ];
    }
}
