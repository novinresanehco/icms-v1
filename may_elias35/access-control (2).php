<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{Cache, Log};
use App\Core\Repository\{RoleRepository, PermissionRepository};
use App\Core\Events\AccessEvent;
use App\Core\Exceptions\{
    AccessDeniedException,
    AuthorizationException,
    SecurityException
};

class AccessControlService
{
    protected RoleRepository $roleRepository;
    protected PermissionRepository $permissionRepository;
    protected array $config;
    protected array $cache = [];

    public function __construct(
        RoleRepository $roleRepository,
        PermissionRepository $permissionRepository
    ) {
        $this->roleRepository = $roleRepository;
        $this->permissionRepository = $permissionRepository;
        $this->config = config('security.access');
    }

    public function checkAccess(object $user, string $permission, array $context = []): bool
    {
        $cacheKey = $this->getPermissionCacheKey($user->id, $permission);
        
        try {
            return Cache::remember($cacheKey, 300, function () use ($user, $permission, $context) {
                $this->validateRequest($user, $permission, $context);
                
                $hasAccess = $this->verifyAccess($user, $permission, $context);
                
                $this->logAccessAttempt($user, $permission, $hasAccess, $context);
                
                return $hasAccess;
            });
            
        } catch (\Exception $e) {
            $this->handleAccessException($e, $user, $permission, $context);
            throw $e;
        }
    }

    public function validatePermissions(object $user, array $permissions, array $context = []): bool
    {
        $results = [];
        
        foreach ($permissions as $permission) {
            try {
                $results[$permission] = $this->checkAccess($user, $permission, $context);
            } catch (\Exception $e) {
                $this->handleAccessException($e, $user, $permission, $context);
                $results[$permission] = false;
            }
        }

        return !in_array(false, $results, true);
    }

    public function grantPermission(object $user, string $permission, array $context = []): void
    {
        try {
            $this->validatePermissionGrant($permission);
            
            $this->roleRepository->addPermissionToUser($user->id, $permission);
            
            $this->clearPermissionCache($user->id);
            
            $this->logPermissionGrant($user, $permission, $context);
            
        } catch (\Exception $e) {
            $this->handleAccessException($e, $user, $permission, $context);
            throw new SecurityException('Failed to grant permission: ' . $e->getMessage());
        }
    }

    public function revokePermission(object $user, string $permission, array $context = []): void
    {
        try {
            $this->validatePermissionRevoke($permission);
            
            $this->roleRepository->removePermissionFromUser($user->id, $permission);
            
            $this->clearPermissionCache($user->id);
            
            $this->logPermissionRevoke($user, $permission, $context);
            
        } catch (\Exception $e) {
            $this->handleAccessException($e, $user, $permission, $context);
            throw new SecurityException('Failed to revoke permission: ' . $e->getMessage());
        }
    }

    public function getUserPermissions(object $user): array
    {
        $cacheKey = "user_permissions:{$user->id}";
        
        return Cache::remember($cacheKey, 3600, function () use ($user) {
            return $this->roleRepository->getUserPermissions($user->id);
        });
    }

    public function validateResourceAccess(object $user, string $resource, string $action, array $context = []): bool
    {
        try {
            $permission = $this->getResourcePermission($resource, $action);
            
            $hasAccess = $this->checkAccess($user, $permission, $context);
            
            if (!$hasAccess) {
                throw new AccessDeniedException(
                    "Access denied to resource: {$resource}, action: {$action}"
                );
            }
            
            $this->logResourceAccess($user, $resource, $action, true, $context);
            
            return true;
            
        } catch (\Exception $e) {
            $this->logResourceAccess($user, $resource, $action, false, $context);
            throw $e;
        }
    }

    protected function verifyAccess(object $user, string $permission, array $context): bool
    {
        // Check direct permission
        if ($this->hasDirectPermission($user, $permission)) {
            return true;
        }

        // Check role-based permissions
        if ($this->hasRolePermission($user, $permission)) {
            return true;
        }

        // Check inherited permissions
        if ($this->hasInheritedPermission($user, $permission)) {
            return true;
        }

        // Check context-based permissions
        if ($this->hasContextPermission($user, $permission, $context)) {
            return true;
        }

        return false;
    }

    protected function validateRequest(object $user, string $permission, array $context): void
    {
        if (!$user) {
            throw new AuthorizationException('Invalid user');
        }

        if (!$this->isValidPermission($permission)) {
            throw new SecurityException('Invalid permission format');
        }

        if (!$this->isUserActive($user)) {
            throw new SecurityException('User account is not active');
        }
    }

    protected function hasDirectPermission(object $user, string $permission): bool
    {
        return in_array($permission, $this->getUserPermissions($user));
    }

    protected function hasRolePermission(object $user, string $permission): bool
    {
        foreach ($user->roles as $role) {
            if ($this->roleHasPermission($role, $permission)) {
                return true;
            }
        }
        return false;
    }

    protected function hasInheritedPermission(object $user, string $permission): bool
    {
        $inheritedPermissions = $this->getInheritedPermissions($permission);
        return array_intersect($inheritedPermissions, $this->getUserPermissions($user)) !== [];
    }

    protected function hasContextPermission(object $user, string $permission, array $context): bool
    {
        $contextValidator = $this->getContextValidator($permission);
        return $contextValidator ? $contextValidator->validate($user, $context) : false;
    }

    protected function validatePermissionGrant(string $permission): void
    {
        if (!$this->isValidPermission($permission)) {
            throw new SecurityException('Invalid permission format');
        }

        if (!$this->permissionExists($permission)) {
            throw new SecurityException('Permission does not exist');
        }
    }

    protected function validatePermissionRevoke(string $permission): void
    {
        if (!$this->isValidPermission($permission)) {
            throw new SecurityException('Invalid permission format');
        }

        if ($this->isCriticalPermission($permission)) {
            throw new SecurityException('Cannot revoke critical permission');
        }
    }

    protected function getResourcePermission(string $resource, string $action): string
    {
        return sprintf('%s:%s', $resource, $action);
    }

    protected function isValidPermission(string $permission): bool
    {
        return preg_match('/^[a-zA-Z0-9_\-:]+$/', $permission) === 1;
    }

    protected function isUserActive(object $user): bool
    {
        return $user->active && !$user->blocked && !$user->deleted_at;
    }

    protected function roleHasPermission(object $role, string $permission): bool
    {
        return in_array($permission, $role->permissions);
    }

    protected function getInheritedPermissions(string $permission): array
    {
        return $this->permissionRepository->getInheritedPermissions($permission);
    }

    protected function getContextValidator(string $permission)
    {
        return $this->config['context_validators'][$permission] ?? null;
    }

    protected function permissionExists(string $permission): bool
    {
        return $this->permissionRepository->exists($permission);
    }

    protected function isCriticalPermission(string $permission): bool
    {
        return in_array($permission, $this->config['critical_permissions']);
    }

    protected function getPermissionCacheKey(int $userId, string $permission): string
    {
        return sprintf('permission:%d:%s', $userId, $permission);
    }

    protected function clearPermissionCache(int $userId): void
    {
        Cache::tags(['permissions', "user:{$userId}"])->flush();
    }

    protected function logAccessAttempt(object $user, string $permission, bool $granted, array $context): void
    {
        event(new AccessEvent('access_attempt', [
            'user_id' => $user->id,
            'permission' => $permission,
            'granted' => $granted,
            'context' => $context,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]));
    }

    protected function logPermissionGrant(object $user, string $permission, array $context): void
    {
        event(new AccessEvent('permission_granted', [
            'user_id' => $user->id,
            'permission' => $permission,
            'context' => $context,
            'granted_by' => auth()->id(),
            'timestamp' => now()
        ]));
    }

    protected function logPermissionRevoke(object $user, string $permission, array $context): void
    {
        event(new AccessEvent('permission_revoked', [
            'user_id' => $user->id,
            'permission' => $permission,
            'context' => $context,
            'revoked_by' => auth()->id(),
            'timestamp' => now()
        ]));
    }

    protected function logResourceAccess(object $user, string $resource, string $action, bool $granted, array $context): void
    {
        event(new AccessEvent('resource_access', [
            'user_id' => $user->id,
            'resource' => $resource,
            'action' => $action,
            'granted' => $granted,
            'context' => $context,
            'timestamp' => now()
        ]));
    }

    protected function handleAccessException(\Exception $e, object $user, string $permission, array $context): void
    {
        Log::error('Access control failure', [
            'user_id' => $user->id,
            'permission' => $permission,
            'context' => $context,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
