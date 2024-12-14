<?php

namespace App\Core\Repositories;

use App\Core\Models\Permission;
use App\Core\Repositories\Contracts\PermissionRepositoryInterface;
use App\Core\Exceptions\PermissionException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\{Cache, DB, Log};

class PermissionRepository implements PermissionRepositoryInterface
{
    protected Permission $model;
    protected const CACHE_TTL = 3600;

    public function __construct(Permission $model)
    {
        $this->model = $model;
    }

    public function find(int $id): ?Permission
    {
        return Cache::remember("permissions.{$id}", self::CACHE_TTL, function () use ($id) {
            return $this->model->with('roles')->find($id);
        });
    }

    public function findByName(string $name, string $guardName = 'web'): ?Permission
    {
        return Cache::remember("permissions.{$guardName}.{$name}", self::CACHE_TTL, function () use ($name, $guardName) {
            return $this->model->where('name', $name)
                             ->where('guard_name', $guardName)
                             ->first();
        });
    }

    public function all(array $filters = []): Collection
    {
        $query = $this->model->with('roles');
        return $this->applyFilters($query, $filters)->get();
    }

    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = $this->model->with('roles');
        return $this->applyFilters($query, $filters)->paginate($perPage);
    }

    public function create(array $data): Permission
    {
        try {
            DB::beginTransaction();

            $permission = $this->model->create($data);

            if (isset($data['roles'])) {
                $permission->roles()->attach($data['roles']);
            }

            DB::commit();
            $this->clearCache();

            return $permission->fresh('roles');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Permission creation failed:', ['error' => $e->getMessage(), 'data' => $data]);
            throw new PermissionException('Failed to create permission: ' . $e->getMessage());
        }
    }

    public function update(Permission $permission, array $data): bool
    {
        try {
            DB::beginTransaction();

            $permission->update($data);

            if (isset($data['roles'])) {
                $permission->roles()->sync($data['roles']);
            }

            DB::commit();
            $this->clearCache($permission->id);

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Permission update failed:', ['id' => $permission->id, 'error' => $e->getMessage()]);
            throw new PermissionException('Failed to update permission: ' . $e->getMessage());
        }
    }

    public function delete(Permission $permission): bool
    {
        try {
            DB::beginTransaction();

            $permission->roles()->detach();
            $permission->users()->detach();
            $permission->delete();

            DB::commit();
            $this->clearCache($permission->id);

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Permission deletion failed:', ['id' => $permission->id, 'error' => $e->getMessage()]);
            throw new PermissionException('Failed to delete permission: ' . $e->getMessage());
        }
    }

    public function getByGuard(string $guardName): Collection
    {
        return Cache::remember("permissions.guard.{$guardName}", self::CACHE_TTL, function () use ($guardName) {
            return $this->model->where('guard_name', $guardName)->get();
        });
    }

    public function getByModule(string $module): Collection
    {
        return Cache::remember("permissions.module.{$module}", self::CACHE_TTL, function () use ($module) {
            return $this->model->where('module', $module)->get();
        });
    }

    public function syncPermissions(array $permissions): void
    {
        try {
            DB::beginTransaction();

            foreach ($permissions as $permission) {
                $this->model->updateOrCreate(
                    ['name' => $permission['name'], 'guard_name' => $permission['guard_name'] ?? 'web'],
                    array_merge($permission, ['guard_name' => $permission['guard_name'] ?? 'web'])
                );
            }

            DB::commit();
            $this->clearCache();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Permission sync failed:', ['error' => $e->getMessage()]);
            throw new PermissionException('Failed to sync permissions: ' . $e->getMessage());
        }
    }

    protected function applyFilters($query, array $filters): object
    {
        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('name', 'like', "%{$filters['search']}%")
                  ->orWhere('description', 'like', "%{$filters['search']}%");
            });
        }

        if (!empty($filters['guard_name'])) {
            $query->where('guard_name', $filters['guard_name']);
        }

        if (!empty($filters['module'])) {
            $query->where('module', $filters['module']);
        }

        if (!empty($filters['role_id'])) {
            $query->whereHas('roles', function ($q) use ($filters) {
                $q->where('roles.id', $filters['role_id']);
            });
        }

        $sort = $filters['sort'] ?? 'name';
        $direction = $filters['direction'] ?? 'asc';
        $query->orderBy($sort, $direction);

        return $query;
    }

    protected function clearCache(?int $permissionId = null): void
    {
        if ($permissionId) {
            Cache::forget("permissions.{$permissionId}");
            $permission = $this->model->find($permissionId);
            if ($permission) {
                Cache::forget("permissions.{$permission->guard_name}.{$permission->name}");
            }
        }
        
        Cache::tags(['permissions'])->flush();
    }
}
