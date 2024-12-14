<?php

namespace App\Repositories;

use App\Models\Role;
use App\Core\Repositories\BaseRepository;
use Illuminate\Database\Eloquent\Collection;

class RoleRepository extends BaseRepository
{
    public function __construct(Role $model)
    {
        $this->model = $model;
        parent::__construct();
    }

    public function findByName(string $name): ?Role
    {
        return $this->executeWithCache(__FUNCTION__, [$name], function () use ($name) {
            return $this->model->where('name', $name)->first();
        });
    }

    public function assignToUser(int $userId, array $roleIds): void
    {
        $user = app(UserRepository::class)->find($userId);
        $user->roles()->sync($roleIds);
        $this->clearCache();
    }

    public function findWithPermissions(): Collection
    {
        return $this->executeWithCache(__FUNCTION__, [], function () {
            return $this->model->with('permissions')->get();
        });
    }

    public function duplicate(int $id): ?Role
    {
        $role = $this->find($id);
        if (!$role) {
            return null;
        }

        $newRole = $this->create([
            'name' => $role->name . ' (Copy)',
            'description' => $role->description
        ]);

        $permissionIds = $role->permissions->pluck('id')->toArray();
        $newRole->permissions()->sync($permissionIds);

        $this->clearCache();
        return $newRole;
    }

    public function findDefault(): ?Role
    {
        return $this->executeWithCache(__FUNCTION__, [], function () {
            return $this->model->where('is_default', true)->first();
        });
    }
}
