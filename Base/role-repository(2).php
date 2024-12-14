<?php

namespace App\Repositories;

use App\Models\Role;
use App\Repositories\Contracts\RoleRepositoryInterface;
use Illuminate\Support\Collection;

class RoleRepository extends BaseRepository implements RoleRepositoryInterface
{
    protected array $searchableFields = ['name', 'description'];
    protected array $filterableFields = ['guard_name'];
    protected array $relationships = ['permissions', 'users'];

    public function __construct(Role $model)
    {
        parent::__construct($model);
    }

    public function syncPermissions(int $roleId, array $permissions): Role
    {
        try {
            DB::beginTransaction();
            
            $role = $this->findOrFail($roleId);
            $role->permissions()->sync($permissions);
            
            DB::commit();
            $this->clearModelCache();
            return $role->load('permissions');
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RepositoryException("Failed to sync permissions: {$e->getMessage()}");
        }
    }

    public function assignToUser(int $roleId, int $userId): void
    {
        $role = $this->findOrFail($roleId);
        $role->users()->syncWithoutDetaching([$userId]);
        $this->clearModelCache();
    }

    public function removeFromUser(int $roleId, int $userId): void
    {
        $role = $this->findOrFail($roleId);
        $role->users()->detach($userId);
        $this->clearModelCache();
    }

    public function getRolesByGuard(string $guardName): Collection
    {
        return Cache::remember(
            $this->getCacheKey("guard.{$guardName}"),
            $this->cacheTTL,
            fn() => $this->model->where('guard_name', $guardName)->get()
        );
    }
}
