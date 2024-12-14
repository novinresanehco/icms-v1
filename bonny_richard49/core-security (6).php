<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Core\Contracts\SecurityManagerInterface;
use App\Core\Exceptions\SecurityException;

class SecurityManager implements SecurityManagerInterface 
{
    private AuthenticationService $auth;
    private AuthorizationService $authz;
    private ValidationService $validator;
    private AuditService $audit;
    
    public function __construct(
        AuthenticationService $auth,
        AuthorizationService $authz,
        ValidationService $validator,
        AuditService $audit
    ) {
        $this->auth = $auth;
        $this->authz = $authz;
        $this->validator = $validator;
        $this->audit = $audit;
    }

    public function validateSecuredOperation(callable $operation, array $context): mixed
    {
        // Pre-operation security checks
        $this->validatePreOperationSecurity($context);
        
        // Start transaction and monitoring
        DB::beginTransaction();
        $startTime = microtime(true);
        
        try {
            // Execute operation with security monitoring
            $result = $this->executeSecureOperation($operation);
            
            // Verify result integrity
            $this->validateResult($result);
            
            // Complete transaction
            DB::commit();
            
            // Audit successful operation
            $this->audit->logSuccess($context, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            // Rollback and audit failure
            DB::rollBack();
            $this->handleSecurityFailure($e, $context);
            
            throw $e;
        } finally {
            // Record metrics
            $this->recordMetrics($startTime, $context);
        }
    }

    private function validatePreOperationSecurity(array $context): void 
    {
        // Authenticate user
        if (!$this->auth->validate($context['user'])) {
            throw new SecurityException("Authentication failed");
        }

        // Verify permissions
        if (!$this->authz->hasPermission($context['user'], $context['permission'])) {
            throw new SecurityException("Unauthorized access attempt");
        }

        // Validate input
        if (!$this->validator->validateInput($context['data'])) {
            throw new SecurityException("Input validation failed");
        }

        // Rate limiting
        $key = "rate_limit:{$context['user']}:{$context['operation']}";
        if (!$this->checkRateLimit($key)) {
            throw new SecurityException("Rate limit exceeded");
        }
    }

    private function executeSecureOperation(callable $operation): mixed
    {
        return $operation();
    }

    private function validateResult($result): void
    {
        if (!$this->validator->validateOutput($result)) {
            throw new SecurityException("Result validation failed");
        }
    }

    private function handleSecurityFailure(\Exception $e, array $context): void
    {
        // Log security failure
        $this->audit->logFailure($e, $context);

        // Notify security team if critical
        if ($this->isCriticalFailure($e)) {
            $this->notifySecurityTeam($e, $context);
        }
    }

    private function checkRateLimit(string $key): bool
    {
        $attempts = (int) Cache::get($key, 0);
        
        if ($attempts >= config('security.rate_limit')) {
            return false;
        }

        Cache::put($key, $attempts + 1, 60);
        return true;
    }

    private function recordMetrics(float $startTime, array $context): void
    {
        $duration = microtime(true) - $startTime;
        
        Log::info('Security operation completed', [
            'duration' => $duration,
            'operation' => $context['operation'],
            'user' => $context['user']->id
        ]);
    }

    private function isCriticalFailure(\Exception $e): bool
    {
        return $e instanceof CriticalSecurityException;
    }

    private function notifySecurityTeam(\Exception $e, array $context): void
    {
        // Implementation for security team notification
    }
}
