<?php

namespace App\Core\Auth\Repository;

use App\Core\Auth\Models\Permission;
use App\Core\Auth\DTO\PermissionData;
use App\Core\Auth\Events\PermissionCreated;
use App\Core\Auth\Events\PermissionUpdated;
use App\Core\Auth\Events\PermissionDeleted;
use App\Core\Auth\Events\PermissionsSynced;
use App\Core\Auth\Exceptions\PermissionNotFoundException;
use App\Core\Auth\Exceptions\PermissionInUseException;
use App\Core\Shared\Repository\BaseRepository;
use App\Core\Shared\Cache\CacheManagerInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

class PermissionRepository extends BaseRepository implements PermissionRepositoryInterface
{
    protected const CACHE_KEY = 'permissions';
    protected const CACHE_TTL = 3600; // 1 hour

    public function __construct(CacheManagerInterface $cache)
    {
        parent::__construct($cache);
        $this->setCacheKey(self::CACHE_KEY);
        $this->setCacheTtl(self::CACHE_TTL);
    }

    protected function getModelClass(): string
    {
        return Permission::class;
    }

    public function findByName(string $name): ?Permission
    {
        return $this->cache->remember(
            $this->getCacheKey("name:{$name}"),
            fn() => $this->model->where('name', $name)->first()
        );
    }

    public function createPermission(PermissionData $data): Permission
    {
        DB::beginTransaction();
        try {
            // Create permission
            $permission = $this->model->create([
                'name' => $data->name,
                'display_name' => $data->displayName,
                'description' => $data->description,
                'group' => $data->group,
                'settings' => $data->settings ?? [],
            ]);

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new PermissionCreated($permission));

            DB::commit();
            return $permission;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function updatePermission(int $id, PermissionData $data): Permission
    {
        DB::beginTransaction();
        try {
            $permission = $this->findOrFail($id);

            // Update permission
            $permission->update([
                'name' => $data->name,
                'display_name' => $data->displayName,
                'description' => $data->description,
                'group' => $data->group,
                'settings' => array_merge($permission->settings ?? [], $data->settings ?? []),
            ]);

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new PermissionUpdated($permission));

            DB::commit();
            return $permission->fresh();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getByGroup(string $group): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey("group:{$group}"),
            fn() => $this->model->where('group', $group)
                               ->orderBy('name')
                               ->get()
        );
    }

    public function getAllGrouped(): array
    {
        return $this->cache->remember(
            $this->getCacheKey('grouped'),
            function() {
                $permissions = $this->model->orderBy('group')
                                         ->orderBy('name')
                                         ->get();

                return $permissions->groupBy('group')->toArray();
            }
        );
    }

    public function getUsageStats(int $permissionId): array
    {
        return $this->cache->remember(
            $this->getCacheKey("stats:{$permissionId}"),
            function() use ($permissionId) {
                $permission = $this->findOrFail($permissionId);

                return [
                    'roles_count' => $permission->roles()->count(),
                    'users_count' => $permission->users()->count(),
                    'roles' => $permission->roles()->pluck('name')->toArray(),
                    'last_used' => $permission->last_used_at,
                ];
            }
        );
    }

    public function syncToRole(int $roleId, array $permissions): void
    {
        DB::beginTransaction();
        try {
            $role = app(RoleRepositoryInterface::class)->findOrFail($roleId);
            
            $oldPermissions = $role->permissions->pluck('id')->toArray();
            $role->permissions()->sync($permissions);

            // Clear cache
            $this->clearCache();
            $role->clearCache();

            // Dispatch event
            Event::dispatch(new PermissionsSynced($role, $oldPermissions, $permissions));

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getByRole(int $roleId): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey("role:{$roleId}"),
            function() use ($roleId) {
                $role = app(RoleRepositoryInterface::class)->findOrFail($roleId);
                return $role->permissions()->orderBy('name')->get();
            }
        );
    }

    public function registerGroup(string $group, array $permissions): Collection
    {
        DB::beginTransaction();
        try {
            $created = collect();

            foreach ($permissions as $permission) {
                $created->push($this->createPermission(new PermissionData([
                    'name' => $permission['name'],
                    'display_name' => $permission['display_name'],
                    'description' => $permission['description'] ?? null,
                    'group' => $group,
                ])));
            }

            DB::commit();
            return $created;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function isInUse(int $permissionId): bool
    {
        $permission = $this->findOrFail($permissionId);
        return $permission->roles()->exists() || $permission->users()->exists();
    }

    public function delete($id): bool
    {
        if ($this->isInUse($id)) {
            throw new PermissionInUseException("Cannot delete permission as it is in use");
        }

        DB::beginTransaction();
        try {
            $permission = $this->findOrFail($id);
            
            $result = $permission->delete();

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new PermissionDeleted($permission));

            DB::commit();
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
