<?php

namespace App\Core\Security;

use App\Core\Monitoring\SecurityMonitorInterface;
use App\Core\Validation\ValidationServiceInterface;
use App\Core\Auth\AuthenticationServiceInterface;
use App\Core\Cache\CacheManagerInterface;
use App\Core\Exception\{
    SecurityException,
    ValidationException,
    AuthenticationException
};

class SecurityManager implements SecurityManagerInterface
{
    private SecurityMonitorInterface $monitor;
    private ValidationServiceInterface $validator;
    private AuthenticationServiceInterface $auth;
    private CacheManagerInterface $cache;
    
    public function __construct(
        SecurityMonitorInterface $monitor,
        ValidationServiceInterface $validator,
        AuthenticationServiceInterface $auth,
        CacheManagerInterface $cache
    ) {
        $this->monitor = $monitor;
        $this->validator = $validator;
        $this->auth = $auth;
        $this->cache = $cache;
    }

    public function validateOperation(
        string $operationType,
        string $userId,
        array $context = []
    ): OperationValidation {
        $operationId = $this->monitor->startOperation('security_validate');
        
        try {
            // Validate session
            $session = $this->validateSession($userId);
            
            // Validate context
            $this->validator->validateContext($context);
            
            // Check permissions
            $this->validatePermissions($userId, $operationType);
            
            // Rate limiting
            $this->checkRateLimits($userId, $operationType);
            
            // Threat detection
            $this->detectThreats($userId, $context);
            
            $this->monitor->recordSuccess($operationId);
            
            return new OperationValidation(true, $session);
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($operationId, $e);
            throw $e;
        }
    }

    private function validateSession(string $userId): Session
    {
        $session = $this->auth->validateSession($userId);
        if (!$session->isValid()) {
            throw new AuthenticationException('Invalid session');
        }
        return $session;
    }

    private function validatePermissions(string $userId, string $operation): void
    {
        if (!$this->auth->hasPermission($userId, $operation)) {
            throw new SecurityException('Permission denied');
        }
    }

    private function checkRateLimits(string $userId, string $operation): void
    {
        $key = "rate_limit:$userId:$operation";
        $count = (int)$this->cache->get($key);
        
        if ($count > $this->getRateLimit($operation)) {
            throw new SecurityException('Rate limit exceeded');
        }
        
        $this->cache->increment($key);
    }

    private function detectThreats(string $userId, array $context): void
    {
        if ($this->monitor->detectAnomalies($userId, $context)) {
            throw new SecurityException('Suspicious activity detected');
        }
    }

    private function getRateLimit(string $operation): int
    {
        return $this->cache->get("rate_limits:$operation") ?? 100;
    }
}
