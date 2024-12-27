<?php

namespace App\Core\Security;

class PermissionRegistry implements PermissionRegistryInterface
{
    private array $permissions = [];
    private CacheManager $cache;

    public function register(string $permission, array $roles): void
    {
        $this->permissions[$permission] = $roles;
        $this->cache->tags(['permissions'])->flush();
    }

    public function checkPermission(Role $role, string $permission): bool
    {
        $cacheKey = "permission:{$role->id}:{$permission}";
        
        return $this->cache->remember($cacheKey, function() use ($role, $permission) {
            return in_array($role->id, $this->permissions[$permission] ?? []);
        });
    }

    public function getRolePermissions(Role $role): array
    {
        $cacheKey = "role_permissions:{$role->id}";
        
        return $this->cache->remember($cacheKey, function() use ($role) {
            return array_keys(array_filter($this->permissions, function($roles) use ($role) {
                return in_array($role->id, $roles);
            }));
        });
    }

    public function clearPermissions(): void
    {
        $this->permissions = [];
        $this->cache->tags(['permissions'])->flush();
    }
}
