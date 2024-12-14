<?php

namespace App\Core\Auth\Repository;

use App\Core\Auth\Models\Role;
use App\Core\Auth\DTO\RoleData;
use App\Core\Auth\Events\RoleCreated;
use App\Core\Auth\Events\RoleUpdated;
use App\Core\Auth\Events\RoleDeleted;
use App\Core\Auth\Events\RolePermissionsChanged;
use App\Core\Auth\Exceptions\RoleNotFoundException;
use App\Core\Shared\Repository\BaseRepository;
use App\Core\Shared\Cache\CacheManagerInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

class RoleRepository extends BaseRepository implements RoleRepositoryInterface
{
    protected const CACHE_KEY = 'roles';
    protected const CACHE_TTL = 3600; // 1 hour

    public function __construct(CacheManagerInterface $cache)
    {
        parent::__construct($cache);
        $this->setCacheKey(self::CACHE_KEY);
        $this->setCacheTtl(self::CACHE_TTL);
    }

    protected function getModelClass(): string
    {
        return Role::class;
    }

    public function findByName(string $name): ?Role
    {
        return $this->cache->remember(
            $this->getCacheKey("name:{$name}"),
            fn() => $this->model->where('name', $name)
                               ->with(['permissions', 'parent'])
                               ->first()
        );
    }

    public function createRole(RoleData $data): Role
    {
        DB::beginTransaction();
        try {
            // Create role
            $role = $this->model->create([
                'name' => $data->name,
                'display_name' => $data->displayName,
                'description' => $data->description,
                'parent_id' => $data->parentId,
                'level' => $this->calculateLevel($data->parentId),
                'settings' => $data->settings ?? []
            ]);

            // Sync permissions
            if (!empty($data->permissions)) {
                $role->permissions()->sync($data->permissions);
            }

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new RoleCreated($role));

            DB::commit();
            return $role->fresh(['permissions', 'parent']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function updateRole(int $id, RoleData $data): Role
    {
        DB::beginTransaction();
        try {
            $role = $this->findOrFail($id);

            // Update role
            $role->update([
                'name' => $data->name,
                'display_name' => $data->displayName,
                'description' => $data->description,
                'parent_id' => $data->parentId,
                'level' => $this->calculateLevel($data->parentId),
                'settings' => array_merge($role->settings ?? [], $data->settings ?? [])
            ]);

            // Sync permissions if provided
            if (isset($data->permissions)) {
                $role->permissions()->sync($data->permissions);
            }

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new RoleUpdated($role));

            DB::commit();
            return $role->fresh(['permissions', 'parent']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getRolesWithPermissions(): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey('with_permissions'),
            fn() => $this->model->with(['permissions', 'parent'])
                               ->orderBy('level')
                               ->orderBy('name')
                               ->get()
        );
    }

    public function syncPermissions(int $roleId, array $permissions): Role
    {
        DB::beginTransaction();
        try {
            $role = $this->findOrFail($roleId);
            
            $oldPermissions = $role->permissions->pluck('id')->toArray();
            $role->permissions()->sync($permissions);

            // Clear cache
            $this->clearCache();

            // Dispatch event
            Event::dispatch(new RolePermissionsChanged($role, $oldPermissions, $permissions));

            DB::commit();
            return $role->fresh(['permissions']);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function getRoleUsersCount(int $roleId): int
    {
        return $this->cache->remember(
            $this->getCacheKey("users_count:{$roleId}"),
            fn() => $this->model->findOrFail($roleId)->users()->count()
        );
    }

    public function hasPermission(int $roleId, string $permission): bool
    {
        $role = $this->findOrFail($roleId);
        return $role->permissions()->where('name', $permission)->exists();
    }

    public function getRoleHierarchy(): array
    {
        return $this->cache->remember(
            $this->getCacheKey('hierarchy'),
            function() {
                $roles = $this->model->with(['children', 'permissions'])
                                   ->whereNull('parent_id')
                                   ->orderBy('level')
                                   ->orderBy('name')
                                   ->get();

                return $this->buildHierarchyTree($roles);
            }
        );
    }

    public function getDescendants(int $roleId): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey("descendants:{$roleId}"),
            function() use ($roleId) {
                $role = $this->findOrFail($roleId);
                return $role->descendants()
                           ->with(['permissions'])
                           ->orderBy('level')
                           ->orderBy('name')
                           ->get();
            }
        );
    }

    public function getAncestors(int $roleId): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey("ancestors:{$roleId}"),
            function() use ($roleId) {
                $role = $this->findOrFail($roleId);
                return $role->ancestors()
                           ->with(['permissions'])
                           ->orderBy('level')
                           ->orderBy('name')
                           ->get();
            }
        );
    }

    protected function calculateLevel(?int $parentId): int
    {
        if (!$parentId) {
            return 1;
        }

        $parent = $this->findOrFail($parentId);
        return $parent->level + 1;
    }

    protected function buildHierarchyTree(Collection $roles): array
    {
        $tree = [];
        foreach ($roles as $role) {
            $node = [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
                'level' => $role->level,
                'permissions' => $role->permissions->pluck('name')->toArray()
            ];

            if ($role->children->isNotEmpty()) {
                $node['children'] = $this->buildHierarchyTree($role->children);
            }

            $tree[] = $node;
        }

        return $tree;
    }
}
