<?php

namespace App\Core\Auth;

use App\Core\Security\CoreSecurityManager;
use App\Core\Services\ValidationService;
use App\Core\Services\AuditService;
use App\Core\Exceptions\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;

class AuthorizationManager
{
    private CoreSecurityManager $security;
    private ValidationService $validator;
    private AuditService $audit;
    private array $config;

    private const CACHE_TTL = 3600; // 1 hour
    private const PERMISSION_PREFIX = 'permission:';
    private const ROLE_PREFIX = 'role:';

    public function __construct(
        CoreSecurityManager $security,
        ValidationService $validator,
        AuditService $audit,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function authorize(string $userId, string $permission, ?string $resourceId = null): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeAuthorization($userId, $permission, $resourceId),
            [
                'operation' => 'authorization_check',
                'user_id' => $userId,
                'permission' => $permission,
                'resource_id' => $resourceId
            ]
        );
    }

    public function assignRole(string $userId, string $roleId): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeRoleAssignment($userId, $roleId),
            ['operation' => 'role_assignment', 'user_id' => $userId, 'role_id' => $roleId]
        );
    }

    public function grantPermission(string $roleId, string $permission): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executePermissionGrant($roleId, $permission),
            ['operation' => 'permission_grant', 'role_id' => $roleId, 'permission' => $permission]
        );
    }

    private function executeAuthorization(string $userId, string $permission, ?string $resourceId): bool
    {
        try {
            // Check user status
            if (!$this->isUserActive($userId)) {
                return false;
            }

            // Check cached permissions first
            if ($this->hasCachedPermission($userId, $permission, $resourceId)) {
                return true;
            }

            // Get user roles
            $roles = $this->getUserRoles($userId);
            if ($roles->isEmpty()) {
                return false;
            }

            // Check role permissions
            if (!$this->hasRolePermission($roles, $permission)) {
                return false;
            }

            // Check resource specific permissions if needed
            if ($resourceId && !$this->checkResourcePermission($userId, $permission, $resourceId)) {
                return false;
            }

            // Cache successful authorization
            $this->cachePermission($userId, $permission, $resourceId);

            return true;

        } catch (\Exception $e) {
            $this->audit->logFailure($e, [
                'user_id' => $userId,
                'permission' => $permission,
                'resource_id' => $resourceId
            ], 'authorization');
            return false;
        }
    }

    private function executeRoleAssignment(string $userId, string $roleId): bool
    {
        try {
            // Validate role exists
            $role = Role::findOrFail($roleId);

            // Check for role assignment restrictions
            if (!$this->canAssignRole($role)) {
                throw new AuthorizationException('Role assignment not allowed');
            }

            // Assign role to user
            UserRole::create([
                'user_id' => $userId,
                'role_id' => $roleId
            ]);

            // Clear user permissions cache
            $this->clearUserPermissionsCache($userId);

            return true;

        } catch (\Exception $e) {
            $this->audit->logFailure($e, [
                'user_id' => $userId,
                'role_id' => $roleId
            ], 'role_assignment');
            throw $e;
        }
    }

    private function executePermissionGrant(string $roleId, string $permission): bool
    {
        try {
            // Validate permission format
            $this->validatePermission($permission);

            // Grant permission to role
            RolePermission::create([
                'role_id' => $roleId,
                'permission' => $permission
            ]);

            // Clear related caches
            $this->clearRolePermissionsCache($roleId);

            return true;

        } catch (\Exception $e) {
            $this->audit->logFailure($e, [
                'role_id' => $roleId,
                'permission' => $permission
            ], 'permission_grant');
            throw $e;
        }
    }

    private function isUserActive(string $userId): bool
    {
        $user = User::find($userId);
        return $user && $user->status === 'active' && !$user->locked_at;
    }

    private function hasCachedPermission(string $userId, string $permission, ?string $resourceId): bool
    {
        $cacheKey = $this->getPermissionCacheKey($userId, $permission, $resourceId);
        return Cache::has($cacheKey);
    }

    private function getUserRoles(string $userId): Collection
    {
        $cacheKey = self::ROLE_PREFIX . $userId;
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function() use ($userId) {
            return UserRole::where('user_id', $userId)
                         ->with('role')
                         ->get()
                         ->pluck('role');
        });
    }

    private function hasRolePermission(Collection $roles, string $permission): bool
    {
        foreach ($roles as $role) {
            if ($this->roleHasPermission($role->id, $permission)) {
                return true;
            }
        }
        return false;
    }

    private function roleHasPermission(string $roleId, string $permission): bool
    {
        $cacheKey = self::PERMISSION_PREFIX . $roleId;
        
        $permissions = Cache::remember($cacheKey, self::CACHE_TTL, function() use ($roleId) {
            return RolePermission::where('role_id', $roleId)
                                ->pluck('permission');
        });

        return $permissions->contains($permission);
    }

    private function checkResourcePermission(string $userId, string $permission, string $resourceId): bool
    {
        // Implement resource specific permission logic
        return true;
    }

    private function cachePermission(string $userId, string $permission, ?string $resourceId): void
    {
        $cacheKey = $this->getPermissionCacheKey($userId, $permission, $resourceId);
        Cache::put($cacheKey, true, self::CACHE_TTL);
    }

    private function getPermissionCacheKey(string $userId, string $permission, ?string $resourceId): string
    {
        $key = self::PERMISSION_PREFIX . "{$userId}:{$permission}";
        return $resourceId ? "{$key}:{$resourceId}" : $key;
    }

    private function canAssignRole(Role $role): bool
    {
        // Implement role assignment validation logic
        return true;
    }

    private function validatePermission(string $permission): void
    {
        if (!preg_match('/^[a-z\-_]+\.[a-z\-_]+$/', $permission)) {
            throw new AuthorizationException('Invalid permission format');
        }
    }

    private function clearUserPermissionsCache(string $userId): void
    {
        Cache::tags(['permissions', "user:{$userId}"])->flush();
    }

    private function clearRolePermissionsCache(string $roleId): void
    {
        Cache::tags(['permissions', "role:{$roleId}"])->flush();
    }
}