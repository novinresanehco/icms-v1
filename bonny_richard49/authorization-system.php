<?php

namespace App\Core\Auth;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Auth\Events\{PermissionGranted, PermissionDenied, RoleAssigned};
use Illuminate\Support\Facades\{Cache, Event};
use App\Core\Exceptions\AuthorizationException;

class AuthorizationManager implements AuthorizationInterface 
{
    private SecurityManagerInterface $security;
    private PermissionRepository $permissions;
    private RoleRepository $roles;
    private AuditLogger $auditLogger;
    private CacheManager $cache;

    private const CACHE_TTL = 3600; // 1 hour
    private const CACHE_PREFIX = 'auth:permissions:';

    public function __construct(
        SecurityManagerInterface $security,
        PermissionRepository $permissions,
        RoleRepository $roles,
        AuditLogger $auditLogger,
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->permissions = $permissions;
        $this->roles = $roles;
        $this->auditLogger = $auditLogger;
        $this->cache = $cache;
    }

    public function checkPermission(User $user, string $permission, ?array $context = null): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->verifyPermission($user, $permission, $context),
            new SecurityContext('permission-check', [
                'user' => $user->id,
                'permission' => $permission,
                'context' => $context
            ])
        );
    }

    private function verifyPermission(User $user, string $permission, ?array $context): bool
    {
        $cacheKey = $this->getCacheKey($user->id, $permission);
        
        return $this->cache->remember($cacheKey, self::CACHE_TTL, function() use ($user, $permission, $context) {
            // Check direct permissions first
            if ($this->hasDirectPermission($user, $permission)) {
                $this->logPermissionGrant($user, $permission, 'direct');
                return true;
            }

            // Check role-based permissions
            if ($this->hasRolePermission($user, $permission)) {
                $this->logPermissionGrant($user, $permission, 'role');
                return true;
            }

            // Check context-based permissions if context provided
            if ($context && $this->hasContextPermission($user, $permission, $context)) {
                $this->logPermissionGrant($user, $permission, 'context');
                return true;
            }

            $this->logPermissionDenied($user, $permission);
            return false;
        });
    }

    private function hasDirectPermission(User $user, string $permission): bool
    {
        return $user->permissions->contains('name', $permission);
    }

    private function hasRolePermission(User $user, string $permission): bool
    {
        return $user->roles->flatMap->permissions->contains('name', $permission);
    }

    private function hasContextPermission(User $user, string $permission, array $context): bool
    {
        $contextualPermissions = $this->permissions->getContextualPermissions($user, $permission);
        
        foreach ($contextualPermissions as $contextPerm) {
            if ($this->evaluateContext($contextPerm, $context)) {
                return true;
            }
        }
        
        return false;
    }

    private function evaluateContext(Permission $permission, array $context): bool
    {
        $rules = $permission->contextRules;
        
        foreach ($rules as $key => $rule) {
            if (!isset($context[$key]) || !$this->matchesRule($context[$key], $rule)) {
                return false;
            }
        }
        
        return true;
    }

    private function matchesRule($value, $rule): bool
    {
        if (is_callable($rule)) {
            return $rule($value);
        }
        
        if (is_array($rule)) {
            return in_array($value, $rule);
        }
        
        return $value === $rule;
    }

    public function grantPermission(User $user, string $permission, ?array $context = null): void
    {
        $this->security->executeCriticalOperation(
            function() use ($user, $permission, $context) {
                $permissionModel = $this->permissions->findOrCreate($permission);
                
                if ($context) {
                    $permissionModel->contextRules = $context;
                    $permissionModel->save();
                }
                
                $user->permissions()->attach($permissionModel->id);
                $this->invalidatePermissionCache($user->id);
                
                Event::dispatch(new PermissionGranted($user, $permission, $context));
            },
            new SecurityContext('grant-permission', [
                'user' => $user->id,
                'permission' => $permission
            ])
        );
    }

    public function revokePermission(User $user, string $permission): void
    {
        $this->security->executeCriticalOperation(
            function() use ($user, $permission) {
                $permissionModel = $this->permissions->findByName($permission);
                if ($permissionModel) {
                    $user->permissions()->detach($permissionModel->id);
                    $this->invalidatePermissionCache($user->id);
                }
            },
            new SecurityContext('revoke-permission', [
                'user' => $user->id,
                'permission' => $permission
            ])
        );
    }

    public function assignRole(User $user, string $role): void
    {
        $this->security->executeCriticalOperation(
            function() use ($user, $role) {
                $roleModel = $this->roles->findOrCreate($role);
                $user->roles()->attach($roleModel->id);
                $this->invalidatePermissionCache($user->id);
                
                Event::dispatch(new RoleAssigned($user, $role));
            },
            new SecurityContext('assign-role', [
                'user' => $user->id,
                'role' => $role
            ])
        );
    }

    private function getCacheKey(int $userId, string $permission): string
    {
        return self::CACHE_PREFIX . "{$userId}:{$permission}";
    }

    private function invalidatePermissionCache(int $userId): void
    {
        $this->cache->deletePattern(self::CACHE_PREFIX . "{$userId}:*");
    }

    private function logPermissionGrant(User $user, string $permission, string $type): void
    {
        $this->auditLogger->logPermissionGrant($user, $permission, $type);
    }

    private function logPermissionDenied(User $user, string $permission): void
    {
        $this->auditLogger->logPermissionDenied($user, $permission);
        Event::dispatch(new PermissionDenied($user, $permission));
    }
}
