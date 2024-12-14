<?php

namespace App\Core;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Contracts\{
    SecurityManagerInterface,
    ValidationServiceInterface,
    AuditLoggerInterface
};
use App\Core\Security\{
    SecurityContext,
    ValidationResult,
    AccessDeniedException,
    ValidationException
};

class CoreCmsManager implements SecurityManagerInterface
{
    private ValidationServiceInterface $validator;
    private AuditLoggerInterface $auditLogger;
    private array $securityConfig;

    public function __construct(
        ValidationServiceInterface $validator,
        AuditLoggerInterface $auditLogger,
        array $securityConfig
    ) {
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
        $this->securityConfig = $securityConfig;
    }

    /**
     * Executes a critical CMS operation with comprehensive protection
     *
     * @param callable $operation Operation to execute
     * @param SecurityContext $context Security context including user and permissions
     * @throws SecurityException If any security validation fails
     * @throws ValidationException If input validation fails
     * @return mixed Operation result
     */
    public function executeCriticalOperation(callable $operation, SecurityContext $context): mixed
    {
        // Start transaction and monitoring
        DB::beginTransaction();
        $operationId = $this->auditLogger->startOperation($context);

        try {
            // Pre-execution validation
            $this->validateOperation($context);

            // Execute operation with monitoring
            $result = $this->executeWithProtection($operation, $context);

            // Verify result
            $this->validateResult($result);

            // Commit and log success
            DB::commit();
            $this->auditLogger->logSuccess($operationId, $result);

            return $result;

        } catch (\Exception $e) {
            // Rollback and handle failure
            DB::rollBack();
            $this->handleFailure($e, $context, $operationId);
            throw $e;
        }
    }

    /**
     * Validates security context and permissions
     */
    private function validateOperation(SecurityContext $context): void
    {
        // Validate request data
        if (!$this->validator->validateRequest($context->getRequest())) {
            throw new ValidationException('Invalid request data');
        }

        // Check permissions
        if (!$this->validator->checkPermissions($context->getUser(), $context->getRequiredPermissions())) {
            throw new AccessDeniedException('Insufficient permissions');
        }

        // Rate limiting
        if (!$this->validator->checkRateLimit($context->getUser())) {
            throw new RateLimitException('Rate limit exceeded');
        }
    }

    /**
     * Executes operation with monitoring and protection
     */
    private function executeWithProtection(callable $operation, SecurityContext $context): mixed
    {
        return Cache::lock('cms-operation-' . $context->getOperationId(), 10)
            ->block(5, function() use ($operation) {
                return $operation();
            });
    }

    /**
     * Validates operation result
     */
    private function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Invalid operation result');
        }
    }

    /**
     * Handles operation failures
     */
    private function handleFailure(\Exception $e, SecurityContext $context, string $operationId): void
    {
        // Log detailed failure information
        $this->auditLogger->logFailure($operationId, [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'context' => $context->toArray()
        ]);

        // Clear any cache entries that might be affected
        Cache::tags(['cms-operations'])->flush();

        // Notify relevant parties if needed
        if ($e instanceof SecurityException) {
            $this->notifySecurityTeam($e, $context);
        }
    }
}

/**
 * Request validation service implementation
 */
class ValidationService implements ValidationServiceInterface
{
    private array $rules;
    private array $rateConfig;

    public function validateRequest(array $request): bool
    {
        foreach ($this->rules as $field => $rule) {
            if (!$this->validateField($request[$field] ?? null, $rule)) {
                return false;
            }
        }
        return true;
    }

    public function checkPermissions(User $user, array $requiredPermissions): bool
    {
        foreach ($requiredPermissions as $permission) {
            if (!$user->hasPermission($permission)) {
                return false;
            }
        }
        return true;
    }

    public function checkRateLimit(User $user): bool
    {
        $key = 'rate-limit:' . $user->getId();
        $attempts = Cache::get($key, 0);
        
        if ($attempts >= $this->rateConfig['max_attempts']) {
            return false;
        }
        
        Cache::put($key, $attempts + 1, $this->rateConfig['window']);
        return true;
    }
}

/**
 * Audit logging implementation
 */
class AuditLogger implements AuditLoggerInterface
{
    public function startOperation(SecurityContext $context): string
    {
        $operationId = uniqid('cms-op-', true);
        
        Log::info('Operation started', [
            'operation_id' => $operationId,
            'user' => $context->getUser()->getId(),
            'action' => $context->getAction(),
            'timestamp' => now()
        ]);
        
        return $operationId;
    }

    public function logSuccess(string $operationId, $result): void
    {
        Log::info('Operation completed successfully', [
            'operation_id' => $operationId,
            'result' => $result,
            'timestamp' => now()
        ]);
    }

    public function logFailure(string $operationId, array $details): void
    {
        Log::error('Operation failed', [
            'operation_id' => $operationId,
            'details' => $details,
            'timestamp' => now()
        ]);
    }
}
