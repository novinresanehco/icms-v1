<?php

namespace App\Core\Security;

class RoleManager implements RoleManagerInterface
{
    private array $hierarchy = [];
    private CacheManager $cache;

    public function getUserRole(User $user): Role
    {
        return $this->cache->remember(
            "user_role:{$user->id}",
            fn() => $user->role
        );
    }

    public function validateUserPermissions(User $user): bool
    {
        $role = $this->getUserRole($user);
        return !empty($this->hierarchy[$role->id]);
    }

    public function setHierarchy(array $hierarchy): void
    {
        $this->hierarchy = $hierarchy;
        $this->cache->tags(['roles'])->flush();
    }

    public function getInheritedRoles(Role $role): array
    {
        return $this->cache->remember(
            "role_inheritance:{$role->id}",
            fn() => $this->resolveInheritance($role->id)
        );
    }

    private function resolveInheritance(int $roleId, array $processed = []): array
    {
        if (!isset($this->hierarchy[$roleId])) {
            return [];
        }

        $roles = [$roleId];
        $processed[] = $roleId;

        foreach ($this->hierarchy[$roleId] as $inheritedId) {
            if (!in_array($inheritedId, $processed)) {
                $roles = array_merge(
                    $roles,
                    $this->resolveInheritance($inheritedId, $processed)
                );
            }
        }

        return array_unique($roles);
    }
}
