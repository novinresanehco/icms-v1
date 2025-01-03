<?php

namespace App\Core\Security;

class EnhancedSecurityManager implements SecurityManagerInterface
{
    private RoleManager $roleManager;
    private ValidationService $validator;
    private AuditLogger $auditLogger;
    private CacheManager $cache;

    public function __construct(
        RoleManager $roleManager,
        ValidationService $validator,
        AuditLogger $auditLogger,
        CacheManager $cache
    ) {
        $this->roleManager = $roleManager;
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
        $this->cache = $cache;
    }

    public function validateCriticalOperation(Operation $operation): OperationResult
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperationContext($operation);
            
            // Execute with monitoring
            $result = $this->executeSecurely($operation);
            
            // Post-execution validation
            $this->validateOperationResult($result);
            
            DB::commit();
            $this->auditLogger->logSuccess($operation);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, $operation);
            throw $e;
        }
    }

    protected function validateOperationContext(Operation $operation): void
    {
        // Validate user permissions
        if (!$this->roleManager->validateUserPermissions($operation->getUser())) {
            throw new SecurityException('Invalid user permissions');
        }

        // Validate operation parameters
        if (!$this->validator->validateOperation($operation)) {
            throw new ValidationException('Invalid operation parameters');
        }

        // Check rate limits
        $this->checkRateLimits($operation);
    }

    protected function executeSecurely(Operation $operation): OperationResult
    {
        $startTime = microtime(true);
        
        try {
            $result = $operation->execute();
            
            // Record metrics
            $this->recordOperationMetrics($operation, $startTime);
            
            return $result;
        } catch (\Exception $e) {
            $this->handleExecutionFailure($e, $operation);
            throw $e;
        }
    }

    protected function validateOperationResult(OperationResult $result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Invalid operation result');
        }
    }

    protected function checkRateLimits(Operation $operation): void
    {
        $key = "rate_limit:{$operation->getType()}:{$operation->getUserId()}";
        
        $attempts = (int)$this->cache->get($key, 0);
        
        if ($attempts >= 3) { // Max 3 attempts per minute
            throw new RateLimitException('Rate limit exceeded');
        }
        
        $this->cache->put($key, $attempts + 1, 60);
    }

    protected function recordOperationMetrics(Operation $operation, float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        
        $this->cache->put(
            "metrics:{$operation->getType()}",
            [
                'duration' => $duration,
                'timestamp' => time(),
                'user_id' => $operation->getUserId()
            ],
            3600
        );
    }

    protected function handleOperationFailure(\Exception $e, Operation $operation): void
    {
        $this->auditLogger->logFailure($e, $operation);
        
        if ($e instanceof SecurityException) {
            $this->handleSecurityFailure($e, $operation);
        }
    }

    protected function handleSecurityFailure(SecurityException $e, Operation $operation): void
    {
        // Increment failure counter
        $key = "security_failures:{$operation->getUserId()}";
        $failures = (int)$this->cache->get($key, 0);
        
        if ($failures >= 5) { // Max 5 failures before lockout
            $this->lockoutUser($operation->getUserId());
        }
        
        $this->cache->put($key, $failures + 1, 3600);
    }

    protected function lockoutUser(int $userId): void
    {
        $this->cache->put("user_lockout:$userId", true, 1800); // 30 minute lockout
        $this->auditLogger->logLockout($userId);
    }
}
