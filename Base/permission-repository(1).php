<?php

namespace App\Repositories;

use App\Models\Permission;
use App\Repositories\Contracts\PermissionRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PermissionRepository extends BaseRepository implements PermissionRepositoryInterface
{
    protected array $searchableFields = ['name', 'description'];
    protected array $filterableFields = ['guard_name', 'module'];

    public function getGrouped(): array
    {
        return Cache::tags(['permissions'])->remember('permissions.grouped', 3600, function() {
            return $this->model
                ->orderBy('module')
                ->get()
                ->groupBy('module')
                ->toArray();
        });
    }

    public function syncForRole(int $roleId, array $permissions): bool
    {
        try {
            $role = app('App\Models\Role')->findOrFail($roleId);
            $role->permissions()->sync($permissions);
            Cache::tags(['permissions', 'roles'])->flush();
            return true;
        } catch (\Exception $e) {
            \Log::error('Error syncing permissions for role: ' . $e->getMessage());
            return false;
        }
    }

    public function getByModule(string $module): Collection
    {
        return $this->model
            ->where('module', $module)
            ->orderBy('name')
            ->get();
    }

    public function createMultiple(array $permissions): bool
    {
        try {
            $this->model->insert($permissions);
            Cache::tags(['permissions'])->flush();
            return true;
        } catch (\Exception $e) {
            \Log::error('Error creating multiple permissions: ' . $e->getMessage());
            return false;
        }
    }
}
