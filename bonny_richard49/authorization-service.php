<?php

namespace App\Core\Security\Services;

use Illuminate\Support\Facades\Cache;
use App\Core\Interfaces\AuthorizationInterface;
use App\Core\Security\Exceptions\AuthorizationException;

class AuthorizationService implements AuthorizationInterface
{
    private PermissionRegistry $permissions;
    private RoleManager $roles;
    private AuditService $audit;
    private array $config;
    private array $cache;

    private const CACHE_TTL = 300; // 5 minutes
    private const MAX_PERMISSION_CHECKS = 1000;

    public function __construct(
        PermissionRegistry $permissions,
        RoleManager $roles,
        AuditService $audit,
        array $config
    ) {
        $this->permissions = $permissions;
        $this->roles = $roles;
        $this->audit = $audit;
        $this->config = $config;
        $this->cache = [];
    }

    public function checkPermission(SecurityContext $context, string $permission): bool
    {
        try {
            $this->validateContext($context);
            
            $cacheKey = $this->generateCacheKey($context->getUserId(), $permission);
            
            return Cache::remember($cacheKey, self::CACHE_TTL, function() use ($context, $permission) {
                return $this->verifyPermission($context, $permission);
            });
        } catch (\Exception $e) {
            $this->handleAuthorizationFailure($e, $context, $permission);
            return false;
        }
    }

    public function hasRole(SecurityContext $context, string $role): bool
    {
        try {
            $this->validateContext($context);
            
            $cacheKey = $this->generateRoleCacheKey($context->getUserId(), $role);
            
            return Cache::remember($cacheKey, self::CACHE_TTL, function() use ($context, $role) {
                return $this->verifyRole($context, $role);
            });
        } catch (\Exception $e) {
            $this->handleAuthorizationFailure($e, $context, "role:$role");
            return false;
        }
    }

    public function checkResourceAccess(SecurityContext $context, Resource $resource): bool
    {
        try {
            $permissions = $resource->getRequiredPermissions();
            $this->validatePermissions($permissions);
            
            foreach ($permissions as $permission) {
                if (!$this->checkPermission($context, $permission)) {
                    return false;
                }
            }
            
            return $this->verifyResourceSpecificRules($context, $resource);
        } catch (\Exception $e) {
            $this->handleAuthorizationFailure($e, $context, "resource:{$resource->getId()}");
            return false;
        }
    }

    public function checkRateLimit(SecurityContext $context, string $operation = null): bool
    {
        $key = $this->generateRateLimitKey($context, $operation);
        $limit = $this->getRateLimit($context, $operation);
        
        $current = Cache::increment($key);
        
        if ($current === 1) {
            Cache::put($key, 1, now()->addMinutes(1));
        }
        
        if ($current > $limit) {
            $this->audit->logRateLimitExceeded($context, $operation);
            return false;
        }
        
        return true;
    }

    private function verifyPermission(SecurityContext $context, string $permission): bool
    {
        // Check direct permissions
        if ($this->hasDirectPermission($context, $permission)) {
            return true;
        }

        // Check role-based permissions
        $roles = $this->roles->getUserRoles($context->getUserId());
        foreach ($roles as $role) {
            if ($this->roleHasPermission($role, $permission)) {
                return true;
            }
        }

        return false;
    }

    private function verifyRole(SecurityContext $context, string $role): bool
    {
        $userRoles = $this->roles->getUserRoles($context->getUserId());
        return in_array($role, $userRoles, true);
    }

    private function hasDirectPermission(SecurityContext $context, string $permission): bool
    {
        $userPermissions = $this->permissions->getUserPermissions($context->getUserId());
        return in_array($permission, $userPermissions, true);
    }

    private function roleHasPermission(string $role, string $permission): bool
    {
        return $this->permissions->getRolePermissions($role)->contains($permission);
    }

    private function verifyResourceSpecificRules(SecurityContext $context, Resource $resource): bool
    {
        foreach ($resource->getAccessRules() as $rule) {
            if (!$rule->verify($context)) {
                return false;
            }
        }
        return true;
    }

    private function validateContext(SecurityContext $context): void
    {
        if (!$context->isAuthenticated()) {
            throw new AuthorizationException('User not authenticated');
        }
        
        if ($context->isExpired()) {
            throw new AuthorizationException('Security context expired');
        }
        
        if (!$this->validateIpAddress($context->getIpAddress())) {
            throw new AuthorizationException('Invalid IP address');
        }
    }

    private function validatePermissions(array $permissions): void
    {
        if (count($permissions) > self::MAX_PERMISSION_CHECKS) {
            throw new AuthorizationException('Too many permission checks requested');
        }

        foreach ($permissions as $permission) {
            if (!$this->permissions->exists($permission)) {
                throw new AuthorizationException("Invalid permission: $permission");
            }
        }
    }

    private function validateIpAddress(string $ip): bool
    {
        if (isset($this->config['ip_whitelist'])) {
            return in_array($ip, $this->config['ip_whitelist'], true);
        }

        if (isset($this->config['ip_blacklist'])) {
            return !in_array($ip, $this->config['ip_blacklist'], true);
        }

        return true;
    }

    private function getRateLimit(SecurityContext $context, ?string $operation): int
    {
        if ($operation && isset($this->config['operation_limits'][$operation])) {
            return $this->config['operation_limits'][$operation];
        }

        return $this->config['default_rate_limit'] ?? 60;
    }

    private function generateCacheKey(int $userId, string $permission): string
    {
        return "auth:perm:{$userId}:{$permission}";
    }

    private function generateRoleCacheKey(int $userId, string $role): string
    {
        return "auth:role:{$userId}:{$role}";
    }

    private function generateRateLimitKey(SecurityContext $context, ?string $operation): string
    {
        $base = "rate_limit:{$context->getUserId()}";
        return $operation ? "{$base}:{$operation}" : $base;
    }

    private function handleAuthorizationFailure(\Exception $e, SecurityContext $context, string $target): void
    {
        $this->audit->logAuthorizationFailure($context, $target, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
