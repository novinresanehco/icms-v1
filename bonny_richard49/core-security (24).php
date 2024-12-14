<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{Cache, Log};
use App\Core\Interfaces\SecurityManagerInterface;
use App\Core\Exceptions\{
    SecurityException,
    ValidationException,
    UnauthorizedException
};

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

    public function executeCriticalOperation(CriticalOperation $operation, SecurityContext $context): OperationResult 
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($operation, $context);
            
            // Execute with monitoring
            $result = $this->executeWithMonitoring($operation, $context);
            
            // Validate result
            $this->validateResult($result);
            
            DB::commit();
            $this->auditLogger->logSuccess($operation, $context);
            
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

    private function validateOperation(CriticalOperation $operation, SecurityContext $context): void
    {
        // Input validation
        $this->validator->validate(
            $operation->getData(),
            $operation->getValidationRules()
        );

        // Check permissions
        if (!$this->accessControl->hasPermission($context, $operation->getRequiredPermissions())) {
            throw new UnauthorizedException('Insufficient permissions');
        }

        // Verify rate limits
        if (!$this->accessControl->checkRateLimit($context, $operation->getRateLimitKey())) {
            throw new SecurityException('Rate limit exceeded');
        }
    }

    private function executeWithMonitoring(CriticalOperation $operation, SecurityContext $context): OperationResult
    {
        $monitor = new OperationMonitor($operation, $context);
        
        try {
            // Execute with real-time monitoring
            $result = $monitor->execute(function() use ($operation) {
                return $operation->execute();
            });

            // Verify result validity
            if (!$result->isValid()) {
                throw new OperationException('Invalid operation result');
            }

            return $result;
            
        } catch (\Exception $e) {
            $monitor->recordFailure($e);
            throw $e;
        }
    }

    private function validateResult(OperationResult $result): void
    {
        // Integrity check
        if (!$this->validator->verifyIntegrity($result)) {
            throw new SecurityException('Result integrity check failed');
        }

        // Business rules validation
        if (!$this->validator->verifyBusinessRules($result)) {
            throw new ValidationException('Business rule validation failed');
        }
    }

    private function handleFailure(CriticalOperation $operation, SecurityContext $context, \Exception $e): void
    {
        // Log detailed failure information
        $this->auditLogger->logFailure($operation, $context, $e);

        // Update monitoring metrics
        $this->updateFailureMetrics($operation, $e);

        // Execute recovery procedures if needed
        $this->executeRecoveryProcedures($operation, $e);
    }
}
