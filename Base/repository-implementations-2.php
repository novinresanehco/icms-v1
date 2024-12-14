<?php

namespace App\Core\Repositories;

use App\Models\User;
use App\Models\Permission;
use App\Models\Role;
use App\Core\Services\Cache\CacheService;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class UserRepository extends AdvancedRepository
{
    protected $model = User::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function createUser(array $data): User
    {
        return $this->executeTransaction(function() use ($data) {
            $user = $this->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => bcrypt($data['password']),
                'status' => $data['status'] ?? 'active',
                'settings' => $data['settings'] ?? [],
                'created_at' => now()
            ]);

            if (!empty($data['roles'])) {
                $user->roles()->attach($data['roles']);
            }

            return $user;
        });
    }

    public function updateUser(User $user, array $data): void
    {
        $this->executeTransaction(function() use ($user, $data) {
            $user->update([
                'name' => $data['name'] ?? $user->name,
                'email' => $data['email'] ?? $user->email,
                'status' => $data['status'] ?? $user->status,
                'settings' => array_merge($user->settings ?? [], $data['settings'] ?? []),
                'updated_at' => now()
            ]);

            if (isset($data['password'])) {
                $user->update(['password' => bcrypt($data['password'])]);
            }

            if (isset($data['roles'])) {
                $user->roles()->sync($data['roles']);
            }

            $this->cache->forget("user:{$user->id}");
        });
    }

    public function findByEmail(string $email): ?User
    {
        return $this->executeQuery(function() use ($email) {
            return $this->model->where('email', $email)->first();
        });
    }

    public function getActiveUsers(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->executeQuery(function() use ($filters, $perPage) {
            $query = $this->model->where('status', 'active');

            if (!empty($filters['role'])) {
                $query->whereHas('roles', function($q) use ($filters) {
                    $q->where('name', $filters['role']);
                });
            }

            if (!empty($filters['search'])) {
                $query->where(function($q) use ($filters) {
                    $q->where('name', 'like', "%{$filters['search']}%")
                      ->orWhere('email', 'like', "%{$filters['search']}%");
                });
            }

            return $query->orderBy('created_at', 'desc')->paginate($perPage);
        });
    }
}

class RoleRepository extends AdvancedRepository
{
    protected $model = Role::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function createRole(array $data): Role
    {
        return $this->executeTransaction(function() use ($data) {
            $role = $this->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? '',
                'created_at' => now()
            ]);

            if (!empty($data['permissions'])) {
                $role->permissions()->attach($data['permissions']);
            }

            return $role;
        });
    }

    public function updateRole(Role $role, array $data): void
    {
        $this->executeTransaction(function() use ($role, $data) {
            $role->update([
                'name' => $data['name'] ?? $role->name,
                'description' => $data['description'] ?? $role->description,
                'updated_at' => now()
            ]);

            if (isset($data['permissions'])) {
                $role->permissions()->sync($data['permissions']);
            }

            $this->cache->forget("role:{$role->id}");
        });
    }

    public function getAllWithPermissions(): Collection
    {
        return $this->executeQuery(function() {
            return $this->model
                ->with('permissions')
                ->orderBy('name')
                ->get();
        });
    }
}

class PermissionRepository extends AdvancedRepository
{
    protected $model = Permission::class;
    protected $cache;

    public function __construct(CacheService $cache)
    {
        parent::__construct();
        $this->cache = $cache;
    }

    public function createPermission(array $data): Permission
    {
        return $this->executeTransaction(function() use ($data) {
            return $this->create([
                'name' => $data['name'],
                'description' => $data['description'] ?? '',
                'group' => $data['group'] ?? 'general',
                'created_at' => now()
            ]);
        });
    }

    public function updatePermission(Permission $permission, array $data): void
    {
        $this->executeTransaction(function() use ($permission, $data) {
            $permission->update([
                'name' => $data['name'] ?? $permission->name,
                'description' => $data['description'] ?? $permission->description,
                'group' => $data['group'] ?? $permission->group,
                'updated_at' => now()
            ]);

            $this->cache->forget("permission:{$permission->id}");
        });
    }

    public function getByGroups(): Collection
    {
        return $this->executeQuery(function() {
            return $this->model
                ->orderBy('group')
                ->orderBy('name')
                ->get()
                ->groupBy('group');
        });
    }
}
