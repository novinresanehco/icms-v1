<?php

namespace App\Core\Security;

class SecurityManager implements SecurityManagerInterface 
{
    private AuthenticationService $auth;
    private AuthorizationService $authz; 
    private ValidationService $validator;
    private AuditService $audit;
    private CacheManager $cache;

    public function validateSecureOperation(
        callable $operation,
        SecurityContext $context
    ): mixed {
        DB::beginTransaction();
        
        try {
            // Pre-operation validation
            $this->validatePreConditions($context);

            // Execute with monitoring
            $monitoringId = $this->startOperationMonitoring($context);
            $result = $this->executeSecurely($operation, $context);
            $this->stopOperationMonitoring($monitoringId);

            // Validate result
            $this->validateResult($result);
            
            DB::commit();
            return $result;

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw $e;
        }
    }

    private function validatePreConditions(SecurityContext $context): void 
    {
        // Authentication check
        if (!$this->auth->isAuthenticated($context)) {
            throw new AuthenticationException();
        }

        // Authorization check
        if (!$this->authz->isAuthorized($context)) {
            throw new AuthorizationException();
        }

        // Input validation
        if (!$this->validator->validateInput($context->getInput())) {
            throw new ValidationException();
        }

        // Rate limiting
        if (!$this->validator->checkRateLimit($context)) {
            throw new RateLimitException();
        }
    }

    private function executeSecurely(
        callable $operation,
        SecurityContext $context
    ): mixed {
        // Cache check
        $cacheKey = $this->generateCacheKey($context);
        if ($cached = $this->cache->get($cacheKey)) {
            return $cached;
        }

        // Execute operation
        $result = $operation();

        // Cache result
        $this->cache->put($cacheKey, $result);

        return $result;
    }

    private function validateResult($result): void 
    {
        if (!$this->validator->validateOutput($result)) {
            throw new ValidationException('Invalid operation result');
        }
    }

    private function handleFailure(\Throwable $e, SecurityContext $context): void 
    {
        // Log failure
        $this->audit->logFailure($e, $context);

        // Clear related cache
        $this->cache->forget($this->generateCacheKey($context));

        // Notify if critical
        if ($this->isCriticalFailure($e)) {
            $this->notifySecurityTeam($e, $context);
        }
    }

    private function startOperationMonitoring(SecurityContext $context): string 
    {
        return $this->audit->startOperation([
            'type' => $context->getOperationType(),
            'user' => $context->getUser()->id,
            'ip' => $context->getIpAddress(),
            'timestamp' => now()
        ]);
    }

    private function stopOperationMonitoring(string $monitoringId): void 
    {
        $this->audit->stopOperation($monitoringId, [
            'completion_time' => now(),
            'memory_peak' => memory_get_peak_usage(true),
            'cpu_time' => getrusage()['ru_utime.tv_sec']
        ]);
    }

    private function generateCacheKey(SecurityContext $context): string 
    {
        return hash('sha256', serialize([
            $context->getOperationType(),
            $context->getUser()->id,
            $context->getInput()
        ]));
    }

    private function isCriticalFailure(\Throwable $e): bool 
    {
        return $e instanceof SecurityException || 
               $e instanceof SystemException ||
               $e instanceof DataCorruptionException;
    }

    private function notifySecurityTeam(\Throwable $e, SecurityContext $context): void 
    {
        event(new CriticalSecurityEvent($e, $context));
    }
}
