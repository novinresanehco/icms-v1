<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Core\Interfaces\SecurityManagerInterface;
use App\Core\Security\Services\{
    ValidationService,
    EncryptionService,
    AuditService
};

class SecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditService $audit;
    private MetricsCollector $metrics;
    
    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditService $audit,
        MetricsCollector $metrics
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->audit = $audit;
        $this->metrics = $metrics;
    }

    /**
     * Execute a critical operation with comprehensive protection
     *
     * @param CriticalOperation $operation
     * @param SecurityContext $context
     * @throws SecurityException
     * @return OperationResult
     */
    public function executeCriticalOperation(
        CriticalOperation $operation, 
        SecurityContext $context
    ): OperationResult {
        // Begin transaction and metrics tracking
        DB::beginTransaction();
        $startTime = microtime(true);

        try {
            // Pre-execution validation
            $this->validateOperation($operation, $context);
            
            // Execute with protection and monitoring
            $result = $this->executeWithProtection($operation, $context);
            
            // Verify results
            $this->verifyResult($result);
            
            // Commit and log success
            DB::commit();
            $this->audit->logSuccess($operation, $context, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            // Rollback and handle failure
            DB::rollBack();
            $this->handleFailure($operation, $context, $e);
            throw new SecurityException(
                'Critical operation failed', 
                $e->getCode(), 
                $e
            );
        } finally {
            // Record metrics
            $this->metrics->record(
                $operation->getType(),
                microtime(true) - $startTime
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

        // Verify permissions
        if (!$this->checkPermissions($context, $operation)) {
            throw new UnauthorizedException();
        }

        // Validate rate limits
        if (!$this->checkRateLimit($context, $operation)) {
            throw new RateLimitExceededException();
        }

        // Additional security checks
        $this->performSecurityChecks($operation, $context);
    }

    private function executeWithProtection(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
        $monitor = new OperationMonitor($operation);
        
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
        // Verify data integrity
        if (!$this->validator->verifyIntegrity($result)) {
            throw new IntegrityException();
        }

        // Additional validations
        $this->performResultValidation($result);
    }

    private function handleFailure(
        CriticalOperation $operation,
        SecurityContext $context,
        \Exception $e
    ): void {
        // Log failure details
        $this->audit->logFailure($operation, $context, $e);
        
        // Notify relevant parties
        $this->notifyFailure($operation, $context, $e);
        
        // Update metrics
        $this->metrics->incrementFailureCount(
            $operation->getType(),
            $e->getCode()
        );
    }

    private function performSecurityChecks(
        CriticalOperation $operation,
        SecurityContext $context
    ): void {
        // Check for suspicious patterns
        if ($this->detectSuspiciousActivity($context)) {
            $this->audit->logSuspiciousActivity($context);
            throw new SecurityException('Suspicious activity detected');
        }

        // Verify security requirements
        foreach ($operation->getSecurityRequirements() as $requirement) {
            if (!$this->validateSecurityRequirement($requirement, $context)) {
                throw new SecurityException(
                    "Security requirement not met: {$requirement}"
                );
            }
        }
    }

    private function performResultValidation(OperationResult $result): void {
        // Validate against business rules
        if (!$this->validator->validateBusinessRules($result)) {
            throw new ValidationException('Business rule validation failed');
        }

        // Check result integrity
        if (!$this->validator->validateResultIntegrity($result)) {
            throw new IntegrityException('Result integrity check failed');
        }
    }
}
