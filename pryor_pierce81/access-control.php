<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{Cache, DB};
use App\Core\Contracts\{AccessControlInterface, ValidatorInterface};
use App\Core\Exceptions\{AccessDeniedException, SecurityException};

class AccessControl implements AccessControlInterface
{
    private SecurityContext $context;
    private ValidatorInterface $validator;
    private AuditLogger $auditLogger;
    private PermissionRegistry $permissions;
    private array $config;

    public function validateAccess(string $resource, string $action): bool
    {
        $startTime = microtime(true);
        
        try {
            // Validate session and context
            $this->validateSession();
            
            // Check rate limits
            $this->checkRateLimit($resource);
            
            // Validate permissions
            $this->validatePermissions($resource, $action);
            
            // Record access attempt
            $this->recordAccess($resource, $action, true);
            
            // Track performance
            $this->trackPerformance($startTime);
            
            return true;
            
        } catch (\Throwable $e) {
            $this->handleAccessFailure($e, $resource, $action);
            throw $e;
        }
    }

    public function checkPermission(string $permission): bool
    {
        return $this->permissions->check(
            $this->context->getUserId(),
            $permission
        );
    }

    public function validateRole(string $role): bool
    {
        $cacheKey = "role_{$this->context->getUserId()}_{$role}";
        
        return Cache::remember($cacheKey, 300, function() use ($role) {
            return $this->permissions->validateRole(
                $this->context->getUserId(),
                $role
            );
        });
    }

    private function validateSession(): void
    {
        if (!$this->context->hasValidSession()) {
            throw new SecurityException('Invalid session');
        }

        if ($this->isSessionCompromised()) {
            $this->terminateSession();
            throw new SecurityException('Session compromised');
        }
    }

    private function validatePermissions(string $resource, string $action): void
    {
        $userId = $this->context->getUserId();
        
        if (!$this->hasResourcePermission($userId, $resource, $action)) {
            $this->auditLogger->logUnauthorizedAccess($resource, $action);
            throw new AccessDeniedException('Permission denied');
        }
    }

    private function checkRateLimit(string $resource): void
    {
        $key = "rate_limit_{$this->context->getUserId()}_{$resource}";
        $limit = $this->config["rate_limits.$resource"] ?? 1000;
        $window = 3600;
        
        $current = Cache::increment($key);
        
        if ($current === 1) {
            Cache::put($key, 1, $window);
        }
        
        if ($current > $limit) {
            $this->auditLogger->logRateLimitExceeded($resource);
            throw new SecurityException('Rate limit exceeded');
        }
    }

    private function hasResourcePermission(int $userId, string $resource, string $action): bool
    {
        $cacheKey = "perm_{$userId}_{$resource}_{$action}";
        
        return Cache::remember($cacheKey, 300, function() use ($userId, $resource, $action) {
            return DB::table('permissions')
                ->join('user_roles', 'permissions.role_id', '=', 'user_roles.role_id')
                ->where('user_roles.user_id', $userId)
                ->where('permissions.resource', $resource)
                ->where('permissions.action', $action)
                ->exists();
        });
    }

    private function isSessionCompromised(): bool
    {
        $session = $this->context->getSession();
        
        return (
            $session->getIp() !== $this->context->getIpAddress() ||
            $session->getUserAgent() !== $this->context->getUserAgent() ||
            $session->getFingerprint() !== $this->context->getFingerprint()
        );
    }

    private function terminateSession(): void
    {
        $this->context->invalidateSession();
        $this->auditLogger->logSessionTermination([
            'reason' => 'security_compromise',
            'user_id' => $this->context->getUserId(),
            'session_id' => $this->context->getSessionId()
        ]);
    }

    private function recordAccess(string $resource, string $action, bool $granted): void
    {
        DB::table('access_log')->insert([
            'user_id' => $this->context->getUserId(),
            'resource' => $resource,
            'action' => $action,
            'granted' => $granted,
            'ip_address' => $this->context->getIpAddress(),
            'user_agent' => $this->context->getUserAgent(),
            'timestamp' => microtime(true),
            'session_id' => $this->context->getSessionId(),
            'request_id' => $this->context->getRequestId()
        ]);
    }

    private function handleAccessFailure(\Throwable $e, string $resource, string $action): void
    {
        $this->recordAccess($resource, $action, false);
        
        $this->auditLogger->logAccessFailure([
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'resource' => $resource,
            'action' => $action,
            'user_id' => $this->context->getUserId(),
            'trace' => $e->getTraceAsString()
        ]);
        
        if ($this->detectBruteForceAttempt()) {
            $this->blockAccess();
        }
    }

    private function detectBruteForceAttempt(): bool
    {
        $key = "failed_attempts_{$this->context->getUserId()}";
        $threshold = $this->config['brute_force_threshold'] ?? 10;
        $window = 300;
        
        $attempts = Cache::increment($key);
        
        if ($attempts === 1) {
            Cache::put($key, 1, $window);
        }
        
        return $attempts > $threshold;
    }

    private function blockAccess(): void
    {
        $duration = $this->config['block_duration'] ?? 3600;
        $key = "blocked_{$this->context->getUserId()}";
        
        Cache::put($key, true, $duration);
        
        $this->auditLogger->logAccessBlocked([
            'user_id' => $this->context->getUserId(),
            'duration' => $duration,
            'reason' => 'brute_force_protection'
        ]);
    }

    private function trackPerformance(float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        
        if ($duration > ($this->config['performance_threshold'] ?? 0.1)) {
            $this->auditLogger->logPerformanceIssue([
                'operation' => 'access_control',
                'duration' => $duration,
                'threshold' => $this->config['performance_threshold']
            ]);
        }
    }
}
