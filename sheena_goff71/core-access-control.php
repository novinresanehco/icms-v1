<?php

namespace App\Core\Security;

class AccessControl implements AccessControlInterface
{
    private PermissionRegistry $permissions;
    private RoleManager $roles;
    private AuditLogger $auditLogger;
    private Cache $cache;
    private MetricsCollector $metrics;

    public function __construct(
        PermissionRegistry $permissions,
        RoleManager $roles,
        AuditLogger $auditLogger,
        Cache $cache,
        MetricsCollector $metrics
    ) {
        $this->permissions = $permissions;
        $this->roles = $roles;
        $this->auditLogger = $auditLogger;
        $this->cache = $cache;
        $this->metrics = $metrics;
    }

    public function authorize(User $user, string $permission, $resource = null): bool
    {
        $operationId = $this->metrics->startOperation('authorize');

        try {
            // Check cache first
            $cacheKey = $this->buildCacheKey($user->id, $permission, $resource);
            if ($cached = $this->cache->get($cacheKey)) {
                return $cached === 'granted';
            }

            // Verify account status
            if (!$this->verifyAccountStatus($user)) {
                return false;
            }

            // Check role-based permissions
            if (!$this->checkRolePermission($user, $permission)) {
                return false;
            }

            // Check resource-specific permissions
            if ($resource && !$this->checkResourcePermission($user, $permission, $resource)) {
                return false;
            }

            // Cache positive result
            $this->cache->put($cacheKey, 'granted', now()->addMinutes(5));
            
            // Log access grant
            $this->auditLogger->logAccessGrant($user, $permission, $resource);

            $this->metrics->recordSuccess($operationId);
            
            return true;

        } catch (\Exception $e) {
            $this->metrics->recordFailure($operationId, $e);
            throw new AccessControlException('Authorization check failed', 0, $e);
        }
    }

    private function checkRolePermission(User $user, string $permission): bool
    {
        foreach ($user->roles as $role) {
            if ($this->roles->hasPermission($role, $permission)) {
                return true;
            }
        }
        return false;
    }

    private function checkResourcePermission(User $user, string $permission, $resource): bool
    {
        return $this->permissions->checkResourcePermission($user, $permission, $resource);
    }

    private function verifyAccountStatus(User $user): bool
    {
        return $user->isActive() && !$user->isSuspended() && !$user->isLocked();
    }

    public function checkRateLimit(User $user, string $operation): bool
    {
        $key = "rate_limit:{$user->id}:{$operation}";
        $limit = $this->config->get("rate_limits.{$operation}", 60);
        $attempts = (int)$this->cache->get($key, 0);

        if ($attempts >= $limit) {
            $this->auditLogger->logRateLimitExceeded($user, $operation);
            return false;
        }

        $this->cache->increment($key, 1, 60);
        return true;
    }

    public function validatePermissions(array $required, array $actual): bool
    {
        foreach ($required as $permission) {
            if (!in_array($permission, $actual)) {
                return false;
            }
        }
        return true;
    }

    public function grantPermission(User $user, string $permission, $resource = null): void
    {
        DB::transaction(function () use ($user, $permission, $resource) {
            // Grant permission
            $this->permissions->grant($user, $permission, $resource);
            
            // Clear cache
            $this->clearPermissionCache($user);
            
            // Log change
            $this->auditLogger->logPermissionGrant($user, $permission, $resource);
        });
    }

    public function revokePermission(User $user, string $permission, $resource = null): void
    {
        DB::transaction(function () use ($user, $permission, $resource) {
            // Revoke permission
            $this->permissions->revoke($user, $permission, $resource);
            
            // Clear cache
            $this->clearPermissionCache($user);
            
            // Log change
            $this->auditLogger->logPermissionRevoke($user, $permission, $resource);
        });
    }

    public function getEffectivePermissions(User $user): array
    {
        $cacheKey = "user_permissions:{$user->id}";
        
        return $this->cache->remember($cacheKey, 60, function () use ($user) {
            return $this->permissions->getEffectivePermissions($user);
        });
    }

    private function buildCacheKey($userId, string $permission, $resource = null): string
    {
        return "auth:{$userId}:{$permission}:" . ($resource ? md5(serialize($resource)) : 'null');
    }

    private function clearPermissionCache(User $user): void
    {
        $this->cache->forget("user_permissions:{$user->id}");
    }

    public function validateAccessChain(User $user, array $operations): ValidationResult
    {
        $result = new ValidationResult();
        
        foreach ($operations as $operation) {
            if (!$this->authorize($user, $operation['permission'], $operation['resource'] ?? null)) {
                $result->addError($operation['permission'], 'Insufficient permissions');
            }
        }
        
        return $result;
    }
}

interface AccessControlInterface
{
    public function authorize(User $user, string $permission, $resource = null): bool;
    public function checkRateLimit(User $user, string $operation): bool;
    public function validatePermissions(array $required, array $actual): bool;
    public function grantPermission(User $user, string $permission, $resource = null): void;
    public function revokePermission(User $user, string $permission, $resource = null): void;
    public function getEffectivePermissions(User $user): array;
    public function validateAccessChain(User $user, array $operations): ValidationResult;
}
