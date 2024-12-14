// File: app/Core/Auth/Authorization/AuthorizationManager.php
<?php

namespace App\Core\Auth\Authorization;

class AuthorizationManager
{
    protected RoleManager $roleManager;
    protected PermissionManager $permissionManager;
    protected PolicyManager $policyManager;
    protected AuthCache $cache;

    public function authorize(User $user, string $permission, $resource = null): bool
    {
        $cacheKey = $this->getCacheKey($user->id, $permission, $resource);

        return $this->cache->remember($cacheKey, function() use ($user, $permission, $resource) {
            // Check direct permissions
            if ($this->hasDirectPermission($user, $permission)) {
                return true;
            }

            // Check role-based permissions
            if ($this->hasRolePermission($user, $permission)) {
                return true;
            }

            // Check policy-based permissions
            if ($resource && $this->satisfiesPolicy($user, $permission, $resource)) {
                return true;
            }

            return false;
        });
    }

    public function hasRole(User $user, string $role): bool
    {
        return $this->roleManager->userHasRole($user, $role);
    }

    public function grantPermission(User $user, string $permission): void
    {
        $this->permissionManager->grantUserPermission($user, $permission);
        $this->cache->invalidateUser($user->id);
    }

    protected function hasDirectPermission(User $user, string $permission): bool
    {
        return $this->permissionManager->userHasPermission($user, $permission);
    }

    protected function hasRolePermission(User $user, string $permission): bool
    {
        foreach ($user->roles as $role) {
            if ($this->permissionManager->roleHasPermission($role, $permission)) {
                return true;
            }
        }
        return false;
    }
}
