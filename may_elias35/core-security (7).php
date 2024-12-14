<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\Log;
use App\Core\Contracts\SecurityManagerInterface;
use App\Core\Contracts\ValidationServiceInterface;
use App\Core\Contracts\AuditServiceInterface;
use App\Core\Contracts\MonitoringServiceInterface;
use App\Core\Exceptions\SecurityException;
use App\Core\Exceptions\ValidationException;

class CoreSecurityManager implements SecurityManagerInterface
{
    private ValidationServiceInterface $validator;
    private AuditServiceInterface $auditor;
    private MonitoringServiceInterface $monitor;
    private array $securityConfig;

    public function __construct(
        ValidationServiceInterface $validator,
        AuditServiceInterface $auditor,
        MonitoringServiceInterface $monitor,
        array $securityConfig
    ) {
        $this->validator = $validator;
        $this->auditor = $auditor;
        $this->monitor = $monitor;
        $this->securityConfig = $securityConfig;
    }

    public function executeSecureOperation(callable $operation, array $context): mixed
    {
        // Create monitoring context for the operation
        $operationId = $this->monitor->startOperation('secure_operation', $context);

        try {
            // Pre-execution validation
            $this->validateOperationContext($context);

            // Begin transaction with monitoring
            DB::beginTransaction();
            
            // Execute operation with real-time monitoring
            $result = $this->executeWithMonitoring($operation, $operationId);
            
            // Validate operation result
            $this->validateOperationResult($result);

            // Commit transaction if all validations pass
            DB::commit();
            
            // Log successful operation
            $this->auditor->logSuccess($operationId, $context, $result);
            
            return $result;

        } catch (\Throwable $e) {
            // Rollback transaction
            DB::rollBack();
            
            // Log failure with full context
            $this->handleOperationFailure($e, $operationId, $context);
            
            throw new SecurityException(
                'Security operation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            // End operation monitoring
            $this->monitor->endOperation($operationId);
        }
    }

    private function validateOperationContext(array $context): void
    {
        if (!$this->validator->validateContext($context)) {
            throw new ValidationException('Invalid operation context');
        }

        if (!$this->validator->checkSecurityConstraints($context)) {
            throw new SecurityException('Security constraints not met');
        }

        // Additional security validations
        $this->performSecurityChecks($context);
    }

    private function executeWithMonitoring(callable $operation, string $operationId): mixed
    {
        return $this->monitor->trackOperation($operationId, function() use ($operation) {
            return $operation();
        });
    }

    private function validateOperationResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Operation result validation failed');
        }
    }

    private function handleOperationFailure(\Throwable $e, string $operationId, array $context): void
    {
        // Log comprehensive failure information
        $this->auditor->logFailure($operationId, $e, $context, [
            'stack_trace' => $e->getTraceAsString(),
            'system_state' => $this->monitor->captureSystemState()
        ]);

        // Increment failure metrics
        $this->monitor->incrementFailureMetric($operationId, $e->getCode());

        // Execute failure recovery if needed
        $this->executeFailureRecovery($e, $context);
    }

    private function performSecurityChecks(array $context): void
    {
        // Verify IP whitelist if required
        if ($context['requires_ip_validation'] ?? false) {
            $this->validateIpAddress($context['ip_address']);
        }

        // Check for suspicious patterns
        if ($this->detectSuspiciousActivity($context)) {
            throw new SecurityException('Suspicious activity detected');
        }

        // Additional security validations
        foreach ($this->securityConfig['required_checks'] as $check) {
            if (!$this->validateSecurityCheck($check, $context)) {
                throw new SecurityException("Security check failed: {$check}");
            }
        }
    }

    private function executeFailureRecovery(\Throwable $e, array $context): void
    {
        try {
            // Implement recovery procedures based on failure type
            if ($e instanceof SecurityException) {
                // Handle security-specific recovery
                $this->handleSecurityFailure($e, $context);
            } else {
                // Handle general operation recovery
                $this->handleGeneralFailure($e, $context);
            }
        } catch (\Exception $recoveryException) {
            // Log recovery failure but don't throw
            Log::error('Recovery procedure failed', [
                'original_exception' => $e->getMessage(),
                'recovery_exception' => $recoveryException->getMessage(),
                'context' => $context
            ]);
        }
    }
}
