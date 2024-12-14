<?php

namespace App\Repositories;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class PermissionRepository extends BaseRepository
{
    /**
     * {@inheritDoc}
     */
    protected function getModel(): Model
    {
        return new Permission();
    }

    /**
     * Find permissions by module.
     *
     * @param string $module
     * @return Collection
     */
    public function findByModule(string $module): Collection
    {
        return $this->model->where('module', $module)->get();
    }

    /**
     * Sync permissions for a role.
     *
     * @param int $roleId
     * @param array<int> $permissionIds
     * @return void
     */
    public function syncRolePermissions(int $roleId, array $permissionIds): void
    {
        $role = \App\Models\Role::findOrFail($roleId);
        $role->permissions()->sync($permissionIds);
    }

    /**
     * Get permissions grouped by module.
     *
     * @return array<string,Collection>
     */
    public function getGroupedByModule(): array
    {
        return $this->model->all()
            ->groupBy('module')
            ->toArray();
    }
}
