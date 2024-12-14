<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\PermissionRepositoryInterface;
use App\Models\Permission;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class PermissionRepository implements PermissionRepositoryInterface
{
    protected Permission $model;

    public function __construct(Permission $model)
    {
        $this->model = $model;
    }

    public function find(int $id): ?Permission
    {
        return $this->model->with('roles')->find($id);
    }

    public function findByName(string $name): ?Permission
    {
        return $this->model->where('name', $name)->first();
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->model
            ->with('roles')
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function create(array $data): Permission
    {
        DB::beginTransaction();
        try {
            $permission = $this->model->create([
                'name' => $data['name'],
                'guard_name' => $data['guard_name'] ?? 'web',
                'module' => $data['module'] ?? null,
                'description' => $data['description'] ?? null
            ]);

            if (!empty($data['roles'])) {
                $permission->assignRole($data['roles']);
            }

            DB::commit();
            return $permission;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): Permission
    {
        DB::beginTransaction();
        try {
            $permission = $this->model->findOrFail($id);
            
            $permission->update([
                'name' => $data['name'] ?? $permission->name,
                'description' => $data['description'] ?? $permission->description,
                'module' => $data['module'] ?? $permission->module
            ]);

            if (isset($data['roles'])) {
                $permission->syncRoles($data['roles']);
            }

            DB::commit();
            return $permission;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function delete(int $id): bool
    {
        DB::beginTransaction();
        try {
            $permission = $this->model->findOrFail($id);
            $permission->roles()->detach();
            $permission->delete();

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getByGuard(string $guard): Collection
    {
        return $this->model->where('guard_name', $guard)->get();
    }

    public function assignToRole(string $permission, string $role): bool
    {
        try {
            $role = Role::findByName($role);
            $role->givePermissionTo($permission);
            return true;
        } catch (\Exception $e) {
            report($e);
            return false;
        }
    }

    public function revokeFromRole(string $permission, string $role): bool
    {
        try {
            $role = Role::findByName($role);
            $role->revokePermissionTo($permission);
            return true;
        } catch (\Exception $e) {
            report($e);
            return false;
        }
    }

    public function syncRolePermissions(string $role, array $permissions): bool
    {
        try {
            $role = Role::findByName($role);
            $role->syncPermissions($permissions);
            return true;
        } catch (\Exception $e) {
            report($e);
            return false;
        }
    }

    public function getUserPermissions(int $userId): Collection
    {
        return $this->model
            ->whereHas('roles.users', function($query) use ($userId) {
                $query->where('model_id', $userId);
            })
            ->get();
    }

    public function getModulePermissions(string $module): Collection
    {
        return $this->model
            ->where('module', $module)
            ->orderBy('name')
            ->get();
    }
}
