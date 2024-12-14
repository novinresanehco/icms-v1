<?php

namespace App\Repositories;

use App\Models\Role;
use App\Repositories\Contracts\RoleRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

class RoleRepository extends BaseRepository implements RoleRepositoryInterface
{
    protected array $searchableFields = ['name', 'display_name', 'description'];
    protected array $filterableFields = ['guard_name'];

    public function getWithPermissions(): Collection
    {
        $cacheKey = 'roles.with_permissions';

        return Cache::tags(['roles'])->remember($cacheKey, 3600, function() {
            return $this->model
                ->with('permissions')
                ->orderBy('name')
                ->get();
        });
    }

    public function syncPermissions(int $roleId, array $permissions): bool
    {
        try {
            $role = $this->find($roleId);
            $role->syncPermissions($permissions);
            
            Cache::tags(['roles', 'permissions'])->flush();
            
            return true;
        } catch (\Exception $e) {
            \Log::error('Error syncing permissions: ' . $e->getMessage());
            return false;
        }
    }

    public function getUsersInRole(int $roleId): Collection
    {
        $cacheKey = 'roles.users.' . $roleId;

        return Cache::tags(['roles'])->remember($cacheKey, 3600, function() use ($roleId) {
            return $this->find($roleId)
                ->users()
                ->with('permissions')
                ->get();
        });
    }

    public function create(array $data): Role
    {
        if (!isset($data['guard_name'])) {
            $data['guard_name'] = 'web';
        }
        
        $role = parent::create($data);
        
        if (isset($data['permissions'])) {
            $role->syncPermissions($data['permissions']);
        }
        
        Cache::tags(['roles'])->flush();
        
        return $role;
    }

    public function update(int $id, array $data): Role
    {
        $role = parent::update($id, $data);
        
        if (isset($data['permissions'])) {
            $role->syncPermissions($data['permissions']);
        }
        
        Cache::tags(['roles'])->flush();
        
        return $role;
    }

    public function delete(int $id): bool
    {
        try {
            $role = $this->find($id);
            
            // Remove role from users before deletion
            $role->users()->detach();
            
            // Remove permissions
            $role->permissions()->detach();
            
            $result = parent::delete($id);
            
            if ($result) {
                Cache::tags(['roles'])->flush();
            }
            
            return $result;
        } catch (\Exception $e) {
            \Log::error('Error deleting role: ' . $e->getMessage());
            return false;
        }
    }

    public function findByName(string $name): ?Role
    {
        $cacheKey = 'roles.name.' . $name;

        return Cache::tags(['roles'])->remember($cacheKey, 3600, function() use ($name) {
            return $this->model
                ->where('name', $name)
                ->first();
        });
    }
}
