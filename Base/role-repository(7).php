<?php

namespace App\Core\Repositories;

use App\Models\Role;
use Illuminate\Support\Collection;

class RoleRepository extends AdvancedRepository
{
    protected $model = Role::class;

    public function createRole(string $name, string $description = null): Role
    {
        return $this->executeTransaction(function() use ($name, $description) {
            return $this->create([
                'name' => $name,
                'description' => $description,
                'created_at' => now()
            ]);
        });
    }

    public function assignUser(int $roleId, int $userId): void
    {
        $this->executeTransaction(function() use ($roleId, $userId) {
            $role = $this->findOrFail($roleId);
            $role->users()->syncWithoutDetaching([$userId]);
            $this->invalidateCache('getUserRoles', $userId);
        });
    }

    public function getUserRoles(int $userId): Collection
    {
        return $this->executeWithCache(__METHOD__, function() use ($userId) {
            return $this->model
                ->whereHas('users', function($query) use ($userId) {
                    $query->where('id', $userId);
                })
                ->get();
        }, $userId);
    }

    public function updateRolePermissions(int $roleId, array $permissionIds): void
    {
        $this->executeTransaction(function() use ($roleId, $permissionIds) {
            $role = $this->findOrFail($roleId);
            $role->permissions()->sync($permissionIds);
            
            // Invalidate related caches
            $this->invalidateCache('getRolePermissions', $roleId);
            foreach ($role->users as $user) {
                $this->invalidateCache('getUserPermissions', $user->id);
            }
        });
    }

    public function removeUser(int $roleId, int $userId): void
    {
        $this->executeTransaction(function() use ($roleId, $userId) {
            $role = $this->findOrFail($roleId);
            $role->users()->detach($userId);
            $this->invalidateCache('getUserRoles', $userId);
        });
    }

    public function getRoleWithPermissions(int $roleId): ?Role
    {
        return $this->executeWithCache(__METHOD__, function() use ($roleId) {
            return $this->model
                ->with('permissions')
                ->find($roleId);
        }, $roleId);
    }
}
