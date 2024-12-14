<?php

namespace App\Core\Repositories;

use App\Models\Role;
use App\Core\Services\Cache\CacheService;
use Illuminate\Support\Collection;

class RoleRepository extends AdvancedRepository
{
    protected $model = Role::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function findByName(string $name): ?Role
    {
        return $this->executeQuery(function() use ($name) {
            return $this->cache->remember("role.name.{$name}", function() use ($name) {
                return $this->model
                    ->where('name', $name)
                    ->first();
            });
        });
    }

    public function getWithPermissions(): Collection
    {
        return $this->executeQuery(function() {
            return $this->cache->remember('roles.with.permissions', function() {
                return $this->model
                    ->with('permissions')
                    ->get();
            });
        });
    }

    public function createWithPermissions(array $data, array $permissions = []): Role
    {
        return $this->executeTransaction(function() use ($data, $permissions) {
            $role = $this->create($data);
            if (!empty($permissions)) {
                $role->permissions()->sync($permissions);
            }
            $this->cache->forget(['roles.with.permissions', "role.name.{$data['name']}"]);
            return $role;
        });
    }

    public function duplicate(Role $role, string $newName): Role
    {
        return $this->executeTransaction(function() use ($role, $newName) {
            $newRole = $this->create([
                'name' => $newName,
                'guard_name' => $role->guard_name,
                'description' => $role->description
            ]);
            
            $newRole->permissions()->sync($role->permissions->pluck('id'));
            $this->cache->forget(['roles.with.permissions', "role.name.{$newName}"]);
            
            return $newRole;
        });
    }
}
