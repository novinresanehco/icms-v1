<?php

namespace App\Core\Security;

use App\Core\Interfaces\AccessControlInterface;
use App\Core\Exceptions\{
    AccessDeniedException,
    SecurityException
};

class AccessControl implements AccessControlInterface 
{
    private PermissionRegistry $permissions;
    private RoleManager $roleManager;
    private AuditLogger $auditLogger;
    private Cache $cache;
    
    public function __construct(
        PermissionRegistry $permissions,
        RoleManager $roleManager,
        AuditLogger $auditLogger,
        Cache $cache
    ) {
        $this->permissions = $permissions;
        $this->roleManager = $roleManager;
        $this->auditLogger = $auditLogger;
        $this->cache = $cache;
    }

    public function hasPermission(SecurityContext $context, array $requiredPermissions): bool 
    {
        try {
            $user = $context->getUser();
            $role = $this->roleManager->getUserRole($user);
            
            // Check cached permissions first
            $cacheKey = $this->getCacheKey($user->id, $role->id);
            
            if ($cachedResult = $this->cache->get($cacheKey)) {
                return $this->validateCachedPermissions($cachedResult, $requiredPermissions);
            }
            
            // Verify each required permission
            foreach ($requiredPermissions as $permission) {
                if (!$this->verifyPermission($role, $permission)) {
                    $this->auditLogger->logAccessDenied($user, $permission);
                    return false;
                }
            }
            
            // Cache successful verification
            $this->cachePermissions($cacheKey, $role->getPermissions());
            
            return true;
            
        } catch (\Exception $e) {
            throw new SecurityException(
                'Permission check failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    public function checkRateLimit(SecurityContext $context, string $key): bool 
    {
        $limits = $this->getRateLimits($context);
        $usage = $this->trackUsage($context, $key);
        
        return $usage <= $limits;
    }

    private function verifyPermission(Role $role, string $permission): bool 
    {
        // Check direct permission
        if ($role->hasPermission($permission)) {
            return true;
        }
        
        // Check inherited permissions
        foreach ($role->getInheritedRoles() as $inheritedRole) {
            if ($this->verifyPermission($inheritedRole, $permission)) {
                return true;
            }
        }
        
        return false;
    }

    private function validateCachedPermissions(array $cachedPermissions, array $required): bool 
    {
        foreach ($required as $permission) {
            if (!in_array($permission, $cachedPermissions)) {
                return false;
            }
        }
        
        return true;
    }

    private function cachePermissions(string $key, array $permissions): void 
    {
        $this->cache->put($key, $permissions, config('security.cache.ttl'));
    }

    private function getCacheKey(int $userId, int $roleId): string 
    {
        return "permissions:{$userId}:{$roleId}";
    }

    private function getRateLimits(SecurityContext $context): array 
    {
        $role = $this->roleManager->getUserRole($context->getUser());
        return $role->getRateLimits();
    }

    private function trackUsage(SecurityContext $context, string $key): int 
    {
        $trackingKey = "usage:{$context->getUser()->id}:{$key}";
        $usage = $this->cache->increment($trackingKey);
        
        if ($usage === 1) {
            $this->cache->expire($trackingKey, config('security.ratelimit.window'));
        }
        
        return $usage;
    }
}
