<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use App\Core\Interfaces\{
    SecurityManagerInterface,
    ValidationServiceInterface,
    AuditServiceInterface
};
use App\Core\Exceptions\{
    SecurityException,
    ValidationException,
    SystemFailureException
};

/**
 * Core security manager implementing critical system protection
 */
class SecurityManager implements SecurityManagerInterface
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditService $auditor;
    private AccessControl $access;
    private MetricsCollector $metrics;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption, 
        AuditService $auditor,
        AccessControl $access,
        MetricsCollector $metrics
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditor = $auditor;
        $this->access = $access;
        $this->metrics = $metrics;
    }

    /**
     * Execute critical operation with comprehensive protection
     *
     * @throws SecurityException
     * @throws ValidationException 
     */
    public function executeCriticalOperation(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
        DB::beginTransaction();
        $startTime = microtime(true);

        try {
            // Validate operation
            $this->validateOperation($operation, $context);
            
            // Execute with protection
            $result = $this->executeProtected($operation, $context);
            
            // Verify result
            $this->verifyResult($result);

            DB::commit();
            $this->logSuccess($operation, $context, $result);
            
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($operation, $context, $e);
            throw new SecurityException('Operation failed: ' . $e->getMessage());

        } finally {
            $this->recordMetrics($operation, microtime(true) - $startTime);
        }
    }

    /**
     * Validates all security aspects of the operation
     */
    private function validateOperation(
        CriticalOperation $operation, 
        SecurityContext $context
    ): void {
        // Validate inputs
        $this->validator->validateInput(
            $operation->getData(),
            $operation->getRules()
        );

        // Check permissions
        if (!$this->access->hasPermission($context, $operation->getRequiredPermissions())) {
            throw new SecurityException('Insufficient permissions');
        }

        // Rate limiting
        if (!$this->access->checkRateLimit($context)) {
            throw new SecurityException('Rate limit exceeded'); 
        }

        // Additional security checks
        if (!$this->performSecurityChecks($operation)) {
            throw new SecurityException('Security check failed');
        }
    }

    /**
     * Executes operation with monitoring and protection
     */
    private function executeProtected(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
        $monitor = new OperationMonitor($operation, $context);

        try {
            $result = $monitor->execute(function() use ($operation) {
                return $operation->execute(); 
            });

            if (!$result->isValid()) {
                throw new ValidationException('Invalid operation result');
            }

            return $result;

        } catch (\Exception $e) {
            $monitor->recordFailure($e);
            throw $e;
        }
    }

    /**
     * Verifies operation result integrity and security
     */
    private function verifyResult(OperationResult $result): void {
        if (!$this->validator->verifyIntegrity($result)) {
            throw new SecurityException('Result integrity check failed');
        }

        if (!$this->validator->verifyBusinessRules($result)) {
            throw new ValidationException('Business rule validation failed');
        }
    }

    /**
     * Handles operation failures with full context
     */
    private function handleFailure(
        CriticalOperation $operation,
        SecurityContext $context,
        \Exception $e
    ): void {
        // Log failure details
        $this->auditor->logFailure(
            $operation,
            $context,
            $e,
            [
                'trace' => $e->getTraceAsString(),
                'input' => $operation->getData(),
                'state' => $this->getSystemState()
            ]
        );

        // Update metrics
        $this->metrics->incrementFailureCount(
            $operation->getType(),
            $e->getCode()
        );

        // Execute recovery if needed
        $this->executeFailureRecovery($operation, $e);
    }

    /**
     * Records comprehensive metrics
     */
    private function recordMetrics(
        CriticalOperation $operation,
        float $executionTime
    ): void {
        $this->metrics->record([
            'operation' => $operation->getType(),
            'execution_time' => $executionTime,
            'memory_peak' => memory_get_peak_usage(true),
            'timestamp' => microtime(true)
        ]);
    }

    /**
     * Performs additional security validations
     */
    private function performSecurityChecks(CriticalOperation $operation): bool {
        // Verify IP whitelist if required
        if ($operation->requiresIpWhitelist()) {
            if (!$this->access->verifyIpWhitelist(request()->ip())) {
                return false;
            }
        }

        // Check for suspicious patterns
        if ($this->detectSuspiciousActivity()) {
            return false;
        }

        // Additional security requirements
        foreach ($operation->getSecurityRequirements() as $requirement) {
            if (!$this->validateSecurityRequirement($requirement)) {
                return false;
            }
        }

        return true;
    }
}
