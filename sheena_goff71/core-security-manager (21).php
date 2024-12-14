<?php

namespace App\Core\Security;

use App\Core\Contracts\SecurityManagerInterface;
use App\Core\Security\Services\{
    ValidationService,
    EncryptionService,
    AuditLogger,
    AccessControl
};
use App\Core\Exceptions\{
    SecurityException,
    ValidationException,
    UnauthorizedException
};
use Illuminate\Support\Facades\DB;

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
     * Validates and executes a critical operation with comprehensive protection
     *
     * @throws SecurityException
     * @throws ValidationException
     */
    public function executeCriticalOperation(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($operation, $context);
            
            // Execute operation with monitoring
            $result = $this->executeWithProtection($operation, $context);
            
            // Verify result integrity
            $this->verifyResult($result);
            
            DB::commit();
            $this->auditLogger->logSuccess($operation, $context, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($operation, $context, $e);
            throw new SecurityException(
                'Critical operation failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

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
            $context, 
            $operation->getRequiredPermissions()
        )) {
            $this->auditLogger->logUnauthorizedAccess($context, $operation);
            throw new UnauthorizedException('Insufficient permissions');
        }

        // Verify rate limits
        if (!$this->accessControl->checkRateLimit(
            $context, 
            $operation->getRateLimitKey()
        )) {
            $this->auditLogger->logRateLimitExceeded($context, $operation);
            throw new RateLimitException('Rate limit exceeded');
        }

        // Additional security checks
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
        if (!$this->validator->verifyIntegrity($result)) {
            throw new IntegrityException('Result integrity check failed');
        }
    }

    private function handleFailure(
        CriticalOperation $operation,
        SecurityContext $context,
        \Exception $e
    ): void {
        $this->auditLogger->logFailure($operation, $context, $e, [
            'stack_trace' => $e->getTraceAsString(),
            'system_state' => $this->captureSystemState()
        ]);
    }

    private function performSecurityChecks(
        CriticalOperation $operation,
        SecurityContext $context
    ): void {
        // Verify IP whitelist if required
        if ($operation->requiresIpWhitelist()) {
            $this->accessControl->verifyIpWhitelist($context->getIpAddress());
        }

        // Additional security requirements
        foreach ($operation->getSecurityRequirements() as $requirement) {
            if (!$this->validateSecurityRequirement($requirement, $context)) {
                throw new SecurityException(
                    "Security requirement not met: {$requirement}"
                );
            }
        }
    }
}
