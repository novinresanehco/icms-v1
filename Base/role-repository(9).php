<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\RoleRepositoryInterface;
use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class RoleRepository extends BaseRepository implements RoleRepositoryInterface
{
    public function __construct(Role $model)
    {
        parent::__construct($model);
    }

    public function getAllWithPermissions(): Collection
    {
        return Cache::tags(['roles'])->remember(
            'roles:with_permissions',
            now()->addDay(),
            fn () => $this->model->with('permissions')->get()
        );
    }

    public function syncPermissions(int $roleId, array $permissions): bool
    {
        $role = $this->find($roleId);
        if (!$role) {
            return false;
        }

        $role->permissions()->sync($permissions);
        Cache::tags(['roles'])->flush();
        
        return true;
    }

    public function createRole(string $name, array $permissions = []): Role
    {
        $role = $this->create([
            'name' => $name,
            'slug' => \Str::slug($name)
        ]);

        if (!empty($permissions)) {
            $role->permissions()->sync($permissions);
        }

        Cache::tags(['roles'])->flush();

        return $role;
    }

    public function findBySlug(string $slug): ?Role
    {
        return Cache::tags(['roles'])->remember(
            "role:slug:{$slug}",
            now()->addDay(),
            fn () => $this->model->where('slug', $slug)->first()
        );
    }

    public function getRoleUsers(int $roleId): Collection
    {
        return Cache::tags(['roles', "role:{$roleId}:users"])->remember(
            "role:{$roleId}:users",
            now()->addHours(6),
            fn () => $this->model->find($roleId)->users()->get()
        );
    }
}
