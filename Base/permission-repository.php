<?php

namespace App\Repositories;

use App\Models\Permission;
use App\Repositories\Contracts\PermissionRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class PermissionRepository extends BaseRepository implements PermissionRepositoryInterface
{
    protected array $searchableFields = ['name', 'display_name', 'description'];
    protected array $filterableFields = ['guard_name', 'module'];

    public function getByModule(string $module): Collection
    {
        $cacheKey = 'permissions.module.' . $module;

        return Cache::tags(['permissions'])->remember($cacheKey, 3600, function() use ($module) {
            return $this->model
                ->where('module', $module)
                ->orderBy('name')
                ->get();
        });
    }

    public function syncRolePermissions(int $roleId, array $permissionIds): bool
    {
        try {
            $role = app('roles')->find($roleId);
            $role->permissions()->sync($permissionIds);
            
            Cache::tags(['permissions', 'roles'])->flush();
            
            return true;
        } catch (\Exception $e) {
            \Log::error('Error syncing role permissions: ' . $e->getMessage());
            return false;
        }
    }

    public function getGroupedPermissions(): array
    {
        $cacheKey = 'permissions.grouped';

        return Cache::tags(['permissions'])->remember($cacheKey, 3600, function() {
            return $this->model
                ->get()
                ->groupBy('module')
                ->toArray();
        });
    }

    public function create(array $data): Permission
    {
        $permission = parent::create($data);
        Cache::tags(['permissions'])->flush();
        return $permission;
    }

    public function update(int $id, array $data): Permission
    {
        $permission = parent::update($id, $data);
        Cache::tags(['permissions'])->flush();
        return $permission;
    }

    public function delete(int $id): bool
    {
        $result = parent::delete($id);
        if ($result) {
            Cache::tags(['permissions'])->flush();
        }
        return $result;
    }

    public function findByName(string $name): ?Permission
    {
        $cacheKey = 'permissions.name.' . $name;

        return Cache::tags(['permissions'])->remember($cacheKey, 3600, function() use ($name) {
            return $this->model
                ->where('name', $name)
                ->first();
        });
    }
}
