<?php

namespace App\Core\Repository;

use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use App\Core\Events\AuthEvents;
use App\Core\Exceptions\AuthRepositoryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class AuthRepository extends BaseRepository
{
    protected const CACHE_TIME = 3600;

    protected function getModelClass(): string
    {
        return User::class;
    }

    public function createUser(array $data): User
    {
        try {
            DB::beginTransaction();

            $user = $this->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'status' => $data['status'] ?? 'active',
                'email_verified_at' => $data['verified'] ?? null,
                'metadata' => $data['metadata'] ?? null
            ]);

            if (!empty($data['roles'])) {
                $user->roles()->sync($data['roles']);
            }

            if (!empty($data['permissions'])) {
                $user->permissions()->sync($data['permissions']);
            }

            DB::commit();
            $this->clearCache($user->id);
            event(new AuthEvents\UserCreated($user));

            return $user;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new AuthRepositoryException("Failed to create user: {$e->getMessage()}");
        }
    }

    public function getUserRoles(int $userId): Collection
    {
        return Cache::tags(['users', "user.{$userId}"])->remember(
            "user.{$userId}.roles",
            self::CACHE_TIME,
            fn() => $this->find($userId)->roles()->with('permissions')->get()
        );
    }

    public function getUserPermissions(int $userId): Collection
    {
        return Cache::tags(['users', "user.{$userId}"])->remember(
            "user.{$userId}.permissions",
            self::CACHE_TIME,
            fn() => $this->find($userId)->getAllPermissions()
        );
    }

    public function createRole(array $data): Role
    {
        try {
            DB::beginTransaction();

            $role = Role::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'guard_name' => $data['guard_name'] ?? 'web'
            ]);

            if (!empty($data['permissions'])) {
                $role->permissions()->sync($data['permissions']);
            }

            DB::commit();
            Cache::tags(['roles'])->flush();
            event(new AuthEvents\RoleCreated($role));

            return $role;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new AuthRepositoryException("Failed to create role: {$e->getMessage()}");
        }
    }

    public function createPermission(array $data): Permission
    {
        try {
            DB::beginTransaction();

            $permission = Permission::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'guard_name' => $data['guard_name'] ?? 'web'
            ]);

            DB::commit();
            Cache::tags(['permissions'])->flush();
            event(new AuthEvents\PermissionCreated($permission));

            return $permission;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new AuthRepositoryException("Failed to create permission: {$e->getMessage()}");
        }
    }

    public function assignRoleToUser(int $userId, int $roleId): void
    {
        try {
            DB::beginTransaction();

            $user = $this->find($userId);
            $role = Role::findOrFail($roleId);

            $user->roles()->syncWithoutDetaching([$roleId]);

            DB::commit();
            $this->clearCache($userId);
            event(new AuthEvents\RoleAssigned($user, $role));

        } catch (\Exception $e) {
            DB::rollBack();
            throw new AuthRepositoryException("Failed to assign role: {$e->getMessage()}");
        }
    }

    public function syncUserRoles(int $userId, array $roleIds): void
    {
        try {
            DB::beginTransaction();

            $user = $this->find($userId);
            $user->roles()->sync($roleIds);

            DB::commit();
            $this->clearCache($userId);
            event(new AuthEvents\RolesSynced($user));

        } catch (\Exception $e) {
            DB::rollBack();
            throw new AuthRepositoryException("Failed to sync roles: {$e->getMessage()}");
        }
    }

    public function revokeRole(int $userId, int $roleId): void
    {
        try {
            DB::beginTransaction();

            $user = $this->find($userId);
            $role = Role::findOrFail($roleId);

            $user->roles()->detach($roleId);

            DB::commit();
            $this->clearCache($userId);
            event(new AuthEvents\RoleRevoked($user, $role));

        } catch (\Exception $e) {
            DB::rollBack();
            throw new AuthRepositoryException("Failed to revoke role: {$e->getMessage()}");
        }
    }

    protected function clearCache(int $userId): void
    {
        Cache::tags([
            'users',
            "user.{$userId}",
            'roles',
            'permissions'
        ])->flush();
    }
}
