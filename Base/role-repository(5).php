<?php

namespace App\Core\Repositories;

use App\Core\Models\Role;
use App\Core\Repositories\Contracts\RoleRepositoryInterface;
use App\Core\Exceptions\RoleException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\{Cache, DB, Log};

class RoleRepository implements RoleRepositoryInterface
{
    protected Role $model;
    protected const CACHE_TTL = 3600;

    public function __construct(Role $model)
    {
        $this->model = $model;
    }

    public function find(int $id): ?Role
    {
        return Cache::remember("roles.{$id}", self::CACHE_TTL, function () use ($id) {
            return $this->model->with('permissions')->find($id);
        });
    }

    public function findByName(string $name): ?Role
    {
        return Cache::remember("roles.name.{$name}", self::CACHE_TTL, function () use ($name) {
            return $this->model->with('permissions')
                             ->where('name', $name)
                             ->first();
        });
    }

    public function all(array $filters = []): Collection
    {
        $query = $this->model->with('permissions');
        return $this->applyFilters($query, $filters)->get();
    }

    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = $this->model->with('permissions');
        return $this->applyFilters($query, $filters)->paginate($perPage);
    }

    public function create(array $data): Role
    {
        try {
            DB::beginTransaction();

            $role = $this->model->create($data);

            if (isset($data['permissions'])) {
                $role->permissions()->attach($data['permissions']);
            }

            DB::commit();
            $this->clearCache();

            return $role->fresh('permissions');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Role creation failed:', ['error' => $e->getMessage(), 'data' => $data]);
            throw new RoleException('Failed to create role: ' . $e->getMessage());
        }
    }

    public function update(Role $role, array $data): bool
    {
        try {
            DB::beginTransaction();

            $role->update($data);

            if (isset($data['permissions'])) {
                $role->permissions()->sync($data['permissions']);
            }

            DB::commit();
            $this->clearCache($role->id);

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Role update failed:', ['id' => $role->id, 'error' => $e->getMessage()]);
            throw new RoleException('Failed to update role: ' . $e->getMessage());
        }
    }

    public function delete(Role $role): bool
    {
        try {
            DB::beginTransaction();

            $role->permissions()->detach();
            $role->users()->detach();
            $role->delete();

            DB::commit();
            $this->clearCache($role->id);

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Role deletion failed:', ['id' => $role->id, 'error' => $e->getMessage()]);
            throw new RoleException('Failed to delete role: ' . $e->getMessage());
        }
    }

    public function attachPermissions(Role $role, array $permissionIds): void
    {
        $role->permissions()->attach($permissionIds);
        $this->clearCache($role->id);
    }

    public function detachPermissions(Role $role, array $permissionIds): void
    {
        $role->permissions()->detach($permissionIds);
        $this->clearCache($role->id);
    }

    public function syncPermissions(Role $role, array $permissionIds): void
    {
        $role->permissions()->sync($permissionIds);
        $this->clearCache($role->id);
    }

    public function getWithPermissions(): Collection
    {
        return Cache::remember('roles.with.permissions', self::CACHE_TTL, function () {
            return $this->model->with('permissions')->get();
        });
    }

    protected function applyFilters($query, array $filters): object
    {
        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('name', 'like', "%{$filters['search']}%")
                  ->orWhere('description', 'like', "%{$filters['search']}%");
            });
        }

        if (!empty($filters['permission_id'])) {
            $query->whereHas('permissions', function ($q) use ($filters) {
                $q->where('permissions.id', $filters['permission_id']);
            });
        }

        if (!empty($filters['guard_name'])) {
            $query->where('guard_name', $filters['guard_name']);
        }

        $sort = $filters['sort'] ?? 'name';
        $direction = $filters['direction'] ?? 'asc';
        $query->orderBy($sort, $direction);

        return $query;
    }

    protected function clearCache(?int $roleId = null): void
    {
        if ($roleId) {
            Cache::forget("roles.{$roleId}");
            $role = $this->model->find($roleId);
            if ($role) {
                Cache::forget("roles.name.{$role->name}");
            }
        }
        
        Cache::tags(['roles'])->flush();
        Cache::forget('roles.with.permissions');
    }
}
