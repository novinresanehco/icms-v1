<?php

namespace App\Core\Repository;

use App\Models\Permission;
use App\Core\Events\PermissionEvents;
use App\Core\Exceptions\PermissionRepositoryException;

class PermissionRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Permission::class;
    }

    /**
     * Get permissions by role
     */
    public function getPermissionsByRole(string $role): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('role', $role),
            $this->cacheTime,
            fn() => $this->model->whereHas('roles', function($query) use ($role) {
                $query->where('name', $role);
            })->get()
        );
    }

    /**
     * Sync role permissions
     */
    public function syncRolePermissions(string $role, array $permissions): void
    {
        try {
            $roleModel = Role::where('name', $role)->firstOrFail();
            
            DB::transaction(function() use ($roleModel, $permissions) {
                $roleModel->permissions()->sync($permissions);
            });

            $this->clearCache();
            Cache::tags(['roles'])->flush();

            event(new PermissionEvents\RolePermissionsSynced($roleModel, $permissions));
        } catch (\Exception $e) {
            throw new PermissionRepositoryException(
                "Failed to sync role permissions: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get permissions by user
     */
    public function getUserPermissions(int $userId): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('user', $userId),
            $this->cacheTime,
            fn() => $this->model->whereHas('users', function($query) use ($userId) {
                $query->where('id', $userId);
            })->orWhereHas('roles', function($query) use ($userId) {
                $query->whereHas('users', function($q) use ($userId) {
                    $q->where('id', $userId);
                });
            })->get()
        );
    }

    /**
     * Grant direct permission to user
     */
    public function grantUserPermission(int $userId, string $permission): void
    {
        try {
            $user = User::findOrFail($userId);
            $permissionModel = $this->findByName($permission);

            $user->permissions()->attach($permissionModel->id);
            $this->clearCache();
            Cache::tags(['users'])->flush();

            event(new PermissionEvents\UserPermissionGranted($user, $permission));
        } catch (\Exception $e) {
            throw new PermissionRepositoryException(
                "Failed to grant user permission: {$e->getMessage()}"
            );
        }
    }

    /**
     * Find permission by name
     */
    public function findByName(string $name): ?Permission
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('name', $name),
            $this->cacheTime,
            fn() => $this->model->where('name', $name)->first()
        );
    }
}
