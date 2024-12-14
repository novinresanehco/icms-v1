<?php

namespace App\Repositories;

use App\Models\Role;
use App\Repositories\Contracts\RoleRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class RoleRepository extends BaseRepository implements RoleRepositoryInterface
{
    protected array $searchableFields = ['name', 'description'];
    protected array $filterableFields = ['guard_name'];

    public function getAllWithPermissions(): Collection
    {
        return Cache::tags(['roles'])->remember('roles.with.permissions', 3600, function() {
            return $this->model
                ->with('permissions')
                ->orderBy('name')
                ->get();
        });
    }

    public function syncPermissions(int $roleId, array $permissions): bool
    {
        try {
            $role = $this->findById($roleId);
            $role->permissions()->sync($permissions);
            Cache::tags(['roles', 'permissions'])->flush();
            return true;
        } catch (\Exception $e) {
            \Log::error('Error syncing role permissions: ' . $e->getMessage());
            return false;
        }
    }

    public function findByName(string $name): ?Role
    {
        return $this->model
            ->where('name', $name)
            ->first();
    }

    public function getRoleStats(): array
    {
        return Cache::tags(['roles'])->remember('roles.stats', 3600, function() {
            return [
                'total_roles' => $this->model->count(),
                'roles_by_users' => $this->model
                    ->withCount('users')
                    ->get()
                    ->pluck('users_count', 'name')
                    ->toArray(),
                'roles_by_permissions' => $this->model
                    ->withCount('permissions')
                    ->get()
                    ->pluck('permissions_count', 'name')
                    ->toArray()
            ];
        });
    }
}
