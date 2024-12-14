<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Core\Security\Encryption\EncryptionService;
use App\Core\Security\Access\AccessControl;
use App\Core\Security\Audit\AuditLogger;
use App\Core\Security\Validation\SecurityValidator;
use App\Core\Monitoring\SecurityMonitor;
use App\Core\Exceptions\SecurityException;

class SecurityManager implements SecurityManagerInterface 
{
    protected AccessControl $access;
    protected EncryptionService $encryption;
    protected AuditLogger $audit;
    protected SecurityValidator $validator;
    protected SecurityMonitor $monitor;

    public function __construct(
        AccessControl $access,
        EncryptionService $encryption,
        AuditLogger $audit,
        SecurityValidator $validator,
        SecurityMonitor $monitor
    ) {
        $this->access = $access;
        $this->encryption = $encryption;
        $this->audit = $audit;
        $this->validator = $validator;
        $this->monitor = $monitor;
    }

    public function executeSecureOperation(string $operation, array $context, callable $action): mixed 
    {
        $operationId = uniqid('sec_', true);
        $startTime = microtime(true);

        try {
            // Pre-execution security checks
            $this->validateSecurityContext($operation, $context);
            
            // Begin monitored transaction
            DB::beginTransaction();
            $this->monitor->startSecurityOperation($operationId);
            
            // Execute with full protection
            $result = $this->executeProtectedOperation($action, $context);
            
            // Validate result and commit
            $this->validateOperationResult($result, $operation);
            DB::commit();
            
            // Log successful operation
            $this->auditSuccess($operation, $context, $operationId);
            
            return $result;

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleSecurityFailure($e, $operation, $context, $operationId);
            throw new SecurityException(
                "Security violation in operation $operation: " . $e->getMessage(),
                previous: $e
            );
        } finally {
            // Record security metrics
            $duration = microtime(true) - $startTime;
            $this->monitor->recordSecurityMetrics($operationId, $operation, $duration);
            $this->monitor->stopSecurityOperation($operationId);
        }
    }

    protected function validateSecurityContext(string $operation, array $context): void 
    {
        // Validate authentication
        if (!$this->validator->validateAuthentication($context)) {
            throw new SecurityException('Invalid authentication');
        }

        // Check authorization
        if (!$this->access->checkPermission($operation, $context)) {
            $this->audit->logUnauthorizedAccess($operation, $context);
            throw new SecurityException('Unauthorized access attempt');
        }

        // Validate inputs
        if (!$this->validator->validateSecurityConstraints($context)) {
            throw new SecurityException('Security constraints validation failed');
        }

        // Check rate limits
        if (!$this->access->checkRateLimit($operation, $context)) {
            $this->audit->logRateLimitExceeded($operation, $context);
            throw new SecurityException('Rate limit exceeded');
        }
    }

    protected function executeProtectedOperation(callable $action, array $context): mixed 
    {
        // Decrypt sensitive data if needed
        $secureContext = $this->prepareSecureContext($context);
        
        try {
            $result = $action($secureContext);
            return $this->secureOperationResult($result);
        } catch (\Throwable $e) {
            $this->audit->logOperationFailure($e, $context);
            throw $e;
        }
    }

    protected function validateOperationResult($result, string $operation): void 
    {
        if (!$this->validator->validateOperationResult($result, $operation)) {
            throw new SecurityException('Operation result validation failed');
        }
    }

    protected function handleSecurityFailure(
        \Throwable $e,
        string $operation,
        array $context,
        string $operationId
    ): void {
        // Log security incident
        Log::critical('Security failure detected', [
            'operation' => $operation,
            'operation_id' => $operationId,
            'exception' => $e->getMessage(),
            'context' => $this->sanitizeContext($context),
            'trace' => $e->getTraceAsString()
        ]);

        // Record security metrics
        $this->monitor->recordSecurityFailure($operationId, $operation, $e);

        // Detailed audit log
        $this->audit->logSecurityIncident(
            $operation,
            $context,
            $e,
            $operationId
        );

        // Execute incident response
        $this->executeSecurityIncidentResponse($e, $operation, $context);
    }

    protected function prepareSecureContext(array $context): array 
    {
        $secureContext = [];
        
        foreach ($context as $key => $value) {
            if ($this->requiresEncryption($key)) {
                $secureContext[$key] = $this->encryption->decrypt($value);
            } else {
                $secureContext[$key] = $value;
            }
        }
        
        return $secureContext;
    }

    protected function secureOperationResult($result): mixed 
    {
        if (is_array($result)) {
            return array_map(
                fn($value) => $this->requiresEncryption($value) ? 
                    $this->encryption->encrypt($value) : 
                    $value,
                $result
            );
        }

        return $this->requiresEncryption($result) ? 
            $this->encryption->encrypt($result) : 
            $result;
    }

    protected function requiresEncryption($data): bool 
    {
        return $this->validator->requiresEncryption($data);
    }

    protected function sanitizeContext(array $context): array 
    {
        return array_map(
            fn($value) => $this->shouldMaskValue($value) ? '[MASKED]' : $value,
            $context
        );
    }

    protected function shouldMaskValue($value): bool 
    {
        return $this->validator->isSensitiveData($value);
    }

    protected function executeSecurityIncidentResponse(
        \Throwable $e,
        string $operation,
        array $context
    ): void {
        // Implement specific incident response protocols
        // This should be customized based on security requirements
    }

    protected function auditSuccess(
        string $operation,
        array $context,
        string $operationId
    ): void {
        $this->audit->logSuccessfulOperation($operation, $context, [
            'operation_id' => $operationId,
            'timestamp' => now(),
            'security_level' => $this->validator->getSecurityLevel($operation)
        ]);
    }
}
