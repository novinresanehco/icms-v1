<?php

namespace App\Repositories;

use App\Models\Permission;
use App\Repositories\Contracts\PermissionRepositoryInterface;
use Illuminate\Support\Collection;

class PermissionRepository extends BaseRepository implements PermissionRepositoryInterface
{
    protected array $searchableFields = ['name', 'description'];
    protected array $filterableFields = ['group'];

    public function __construct(Permission $model)
    {
        parent::__construct($model);
    }

    public function syncForRole(int $roleId, array $permissions): bool
    {
        try {
            DB::beginTransaction();

            $role = app(RoleRepository::class)->find($roleId);
            if (!$role) {
                throw new \Exception('Role not found');
            }

            $role->permissions()->sync($permissions);

            DB::commit();
            $this->clearModelCache();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to sync permissions: ' . $e->getMessage());
            return false;
        }
    }

    public function getByGroup(): Collection
    {
        try {
            return Cache::remember(
                $this->getCacheKey('grouped'),
                $this->cacheTTL,
                fn() => $this->model->get()->groupBy('group')
            );
        } catch (\Exception $e) {
            Log::error('Failed to get grouped permissions: ' . $e->getMessage());
            return new Collection();
        }
    }

    public function createMultiple(array $permissions): bool
    {
        try {
            DB::beginTransaction();

            foreach ($permissions as $permission) {
                $this->create([
                    'name' => $permission['name'],
                    'slug' => Str::slug($permission['name']),
                    'description' => $permission['description'] ?? null,
                    'group' => $permission['group'] ?? 'general'
                ]);
            }

            DB::commit();
            $this->clearModelCache();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create permissions: ' . $e->getMessage());
            return false;
        }
    }
}
