<?php

namespace App\Core\Repositories;

use App\Models\Permission;
use App\Core\Services\Cache\CacheService;
use Illuminate\Support\Collection;

class PermissionRepository extends AdvancedRepository
{
    protected $model = Permission::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function getAllGrouped(): Collection
    {
        return $this->executeQuery(function() {
            return $this->cache->remember('permissions.grouped', function() {
                return $this->model
                    ->get()
                    ->groupBy('module');
            });
        });
    }

    public function getByRole(int $roleId): Collection
    {
        return $this->executeQuery(function() use ($roleId) {
            return $this->cache->remember("role.{$roleId}.permissions", function() use ($roleId) {
                return $this->model
                    ->whereHas('roles', function($query) use ($roleId) {
                        $query->where('id', $roleId);
                    })
                    ->get();
            });
        });
    }

    public function syncPermissions(int $roleId, array $permissions): void
    {
        $this->executeTransaction(function() use ($roleId, $permissions) {
            $role = app(RoleRepository::class)->findOrFail($roleId);
            $role->permissions()->sync($permissions);
            $this->cache->forget("role.{$roleId}.permissions");
        });
    }

    public function createForModule(string $module, array $permissions): void
    {
        $this->executeTransaction(function() use ($module, $permissions) {
            foreach ($permissions as $permission) {
                $this->create([
                    'name' => $permission,
                    'module' => $module,
                    'guard_name' => 'web'
                ]);
            }
            $this->cache->forget('permissions.grouped');
        });
    }
}
