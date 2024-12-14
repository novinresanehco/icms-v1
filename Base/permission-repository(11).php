<?php

namespace App\Repositories;

use App\Models\Permission;
use App\Core\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class PermissionRepository extends BaseRepository
{
    public function __construct(Permission $model)
    {
        $this->model = $model;
        parent::__construct();
    }

    public function findByRole(int $roleId): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [$roleId], function () use ($roleId) {
            return $this->model->whereHas('roles', function ($query) use ($roleId) {
                $query->where('id', $roleId);
            })->get();
        });
    }

    public function syncPermissions(int $roleId, array $permissionIds): void
    {
        $role = app(RoleRepository::class)->find($roleId);
        $role->permissions()->sync($permissionIds);
        $this->clearCache();
    }

    public function findByGroup(string $group): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [$group], function () use ($group) {
            return $this->model->where('group', $group)
                             ->orderBy('name')
                             ->get();
        });
    }

    public function createMany(array $permissions): Collection
    {
        $created = collect();
        foreach ($permissions as $permission) {
            $created->push($this->create($permission));
        }
        
        $this->clearCache();
        return $created;
    }

    public function deleteByGroup(string $group): int
    {
        $count = $this->model->where('group', $group)->delete();
        $this->clearCache();
        return $count;
    }
}
