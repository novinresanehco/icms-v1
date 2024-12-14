<?php

namespace App\Core\Security;

use App\Core\Contracts\SecurityManagerInterface;
use App\Core\Security\Services\{ValidationService, EncryptionService, AuditLogger};
use App\Core\Security\Models\{SecurityContext, OperationResult};
use App\Core\Exceptions\{SecurityException, ValidationException};
use Illuminate\Support\Facades\DB;

class SecurityManager implements SecurityManagerInterface
{
    protected ValidationService $validator;
    protected EncryptionService $encryption;
    protected AuditLogger $auditLogger;
    protected array $securityConfig;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        array $securityConfig
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->securityConfig = $securityConfig;
    }

    public function executeCriticalOperation(callable $operation, SecurityContext $context): OperationResult 
    {
        // Start monitoring and transaction
        $operationId = uniqid('op_', true);
        $startTime = microtime(true);
        
        DB::beginTransaction();
        
        try {
            // Pre-execution security validation
            $this->validateOperation($context);
            
            // Execute operation with security context
            $result = $operation($context);
            
            // Verify operation result
            $this->validateResult($result);
            
            // Log successful operation
            $this->auditLogger->logSuccess($operationId, $context, $result);
            
            DB::commit();
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            // Log failure with full context
            $this->auditLogger->logFailure($operationId, $e, $context);
            
            // Handle the error based on type
            $this->handleSecurityFailure($e);
            
            throw new SecurityException(
                'Security operation failed: ' . $e->getMessage(), 
                $e->getCode(), 
                $e
            );
        } finally {
            // Record metrics regardless of outcome
            $this->recordMetrics($operationId, microtime(true) - $startTime);
        }
    }

    protected function validateOperation(SecurityContext $context): void
    {
        if (!$this->validator->validateContext($context)) {
            throw new ValidationException('Invalid security context');
        }

        if (!$this->validator->checkPermissions($context)) {
            throw new SecurityException('Insufficient permissions');
        }

        // Validate rate limits
        if (!$this->checkRateLimits($context)) {
            throw new SecurityException('Rate limit exceeded');
        }
    }

    protected function validateResult(OperationResult $result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Operation result validation failed');
        }

        if (!$this->encryption->verifyIntegrity($result->getData())) {
            throw new SecurityException('Result integrity check failed');
        }
    }

    protected function checkRateLimits(SecurityContext $context): bool
    {
        $key = sprintf(
            'rate_limit:%s:%s',
            $context->getUserId(),
            $context->getOperationType()
        );
        
        return $this->rateLimiter->check($key, $this->securityConfig['rate_limits']);
    }

    protected function handleSecurityFailure(\Exception $e): void
    {
        if ($e instanceof ValidationException) {
            // Handle validation failures
            $this->handleValidationFailure($e);
        } else if ($e instanceof SecurityException) {
            // Handle security violations
            $this->handleSecurityViolation($e);
        } else {
            // Handle unexpected errors
            $this->handleUnexpectedError($e);
        }
    }

    protected function recordMetrics(string $operationId, float $duration): void
    {
        // Record detailed operation metrics
        $this->metrics->record([
            'operation_id' => $operationId,
            'duration' => $duration,
            'memory_peak' => memory_get_peak_usage(true),
            'timestamp' => time()
        ]);
    }
}
