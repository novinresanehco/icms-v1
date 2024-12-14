<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Core\Interfaces\SecurityManagerInterface;
use App\Core\Exceptions\{SecurityException, ValidationException};
use App\Core\Support\{ValidationService, EncryptionService, AuditLogger};

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

    public function executeCriticalOperation(CriticalOperation $operation): OperationResult 
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($operation);
            
            // Execute with monitoring
            $result = $this->executeWithProtection($operation);
            
            // Verify result integrity
            $this->verifyResult($result);
            
            DB::commit();
            $this->auditLogger->logSuccess($operation, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($operation, $e);
            throw new SecurityException(
                'Critical operation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    protected function validateOperation(CriticalOperation $operation): void
    {
        // Validate inputs
        $this->validator->validateInput(
            $operation->getData(), 
            $operation->getValidationRules()
        );

        // Check permissions
        if (!$this->accessControl->hasPermission($operation)) {
            throw new UnauthorizedException();
        }

        // Verify rate limits
        if (!$this->accessControl->checkRateLimit($operation)) {
            throw new RateLimitException();
        }

        // Additional security checks
        $this->performSecurityChecks($operation);
    }

    protected function executeWithProtection(CriticalOperation $operation): OperationResult
    {
        // Create operation monitor
        $monitor = new OperationMonitor($operation);
        
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

    protected function verifyResult(OperationResult $result): void 
    {
        // Verify data integrity
        if (!$this->validator->verifyIntegrity($result)) {
            throw new IntegrityException();
        }

        // Verify business rules
        if (!$this->validator->verifyBusinessRules($result)) {
            throw new BusinessRuleException();
        }

        // Additional result validation
        $this->performResultValidation($result);
    }

    protected function handleFailure(CriticalOperation $operation, \Exception $e): void
    {
        // Log failure with complete context
        $this->auditLogger->logFailure(
            $operation,
            $e,
            $this->getFailureContext()
        );

        // Execute failure recovery if needed
        $this->executeFailureRecovery($operation, $e);

        // Update metrics
        $this->updateFailureMetrics($operation, $e);
    }

    protected function performSecurityChecks(CriticalOperation $operation): void
    {
        // Verify IP whitelist if required
        if ($operation->requiresIpWhitelist()) {
            $this->accessControl->verifyIpWhitelist();
        }

        // Check for suspicious patterns
        if ($this->detectSuspiciousActivity()) {
            throw new SecurityException('Suspicious activity detected');
        }

        // Additional operation-specific checks
        foreach ($operation->getSecurityRequirements() as $requirement) {
            $this->validateSecurityRequirement($requirement);
        }
    }
}
