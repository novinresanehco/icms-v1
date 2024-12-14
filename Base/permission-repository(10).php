<?php

namespace App\Core\Repositories;

use App\Models\Permission;
use Illuminate\Support\Collection;

class PermissionRepository extends AdvancedRepository
{
    protected $model = Permission::class;

    public function createPermission(string $name, string $group, string $description = null): Permission
    {
        return $this->executeTransaction(function() use ($name, $group, $description) {
            return $this->create([
                'name' => $name,
                'group' => $group,
                'description' => $description,
                'created_at' => now()
            ]);
        });
    }

    public function assignToRole(int $permissionId, int $roleId): void
    {
        $this->executeTransaction(function() use ($permissionId, $roleId) {
            $permission = $this->findOrFail($permissionId);
            $permission->roles()->syncWithoutDetaching([$roleId]);
            $this->invalidateCache('getRolePermissions', $roleId);
        });
    }

    public function getRolePermissions(int $roleId): Collection
    {
        return $this->executeWithCache(__METHOD__, function() use ($roleId) {
            return $this->model
                ->whereHas('roles', function($query) use ($roleId) {
                    $query->where('id', $roleId);
                })
                ->get();
        }, $roleId);
    }

    public function getUserPermissions(int $userId): Collection
    {
        return $this->executeWithCache(__METHOD__, function() use ($userId) {
            return $this->model
                ->whereHas('roles.users', function($query) use ($userId) {
                    $query->where('id', $userId);
                })
                ->get();
        }, $userId);
    }

    public function getByGroup(string $group): Collection
    {
        return $this->executeWithCache(__METHOD__, function() use ($group) {
            return $this->model
                ->where('group', $group)
                ->orderBy('name')
                ->get();
        }, $group);
    }

    public function removeFromRole(int $permissionId, int $roleId): void
    {
        $this->executeTransaction(function() use ($permissionId, $roleId) {
            $permission = $this->findOrFail($permissionId);
            $permission->roles()->detach($roleId);
            $this->invalidateCache('getRolePermissions', $roleId);
        });
    }

    public function hasPermission(int $userId, string $permissionName): bool
    {
        return $this->executeWithCache(__METHOD__, function() use ($userId, $permissionName) {
            return $this->model
                ->where('name', $permissionName)
                ->whereHas('roles.users', function($query) use ($userId) {
                    $query->where('id', $userId);
                })
                ->exists();
        }, $userId, $permissionName);
    }
}
