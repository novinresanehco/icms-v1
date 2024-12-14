<?php

namespace App\Repositories;

use App\Models\Permission;
use App\Repositories\Contracts\PermissionRepositoryInterface;
use Illuminate\Support\Collection;

class PermissionRepository extends BaseRepository implements PermissionRepositoryInterface
{
    protected array $searchableFields = ['name', 'description'];
    protected array $filterableFields = ['guard_name', 'module'];
    protected array $relationships = ['roles'];

    public function __construct(Permission $model)
    {
        parent::__construct($model);
    }

    public function getByModule(string $module): Collection
    {
        return Cache::remember(
            $this->getCacheKey("module.{$module}"),
            $this->cacheTTL,
            fn() => $this->model->where('module', $module)->get()
        );
    }

    public function getByGuard(string $guardName): Collection
    {
        return Cache::remember(
            $this->getCacheKey("guard.{$guardName}"),
            $this->cacheTTL,
            fn() => $this->model->where('guard_name', $guardName)->get()
        );
    }

    public function syncRoles(int $permissionId, array $roles): Permission
    {
        try {
            DB::beginTransaction();
            
            $permission = $this->findOrFail($permissionId);
            $permission->roles()->sync($roles);
            
            DB::commit();
            $this->clearModelCache();
            return $permission->load('roles');
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RepositoryException("Failed to sync roles: {$e->getMessage()}");
        }
    }

    public function createForModule(string $module, array $permissions): Collection
    {
        try {
            DB::beginTransaction();
            
            $created = collect();
            foreach ($permissions as $permission) {
                $created->push($this->create([
                    'name' => "{$module}.{$permission}",
                    'module' => $module,
                    'guard_name' => 'web'
                ]));
            }
            
            DB::commit();
            $this->clearModelCache();
            return $created;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RepositoryException("Failed to create module permissions: {$e->getMessage()}");
        }
    }
}
