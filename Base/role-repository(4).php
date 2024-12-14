<?php

namespace App\Repositories;

use App\Repositories\Contracts\RoleRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class RoleRepository implements RoleRepositoryInterface
{
    protected string $rolesTable = 'roles';
    protected string $permissionsTable = 'permissions';
    protected string $rolePermissionsTable = 'role_permissions';
    protected string $userRolesTable = 'user_roles';

    /**
     * Create new role
     *
     * @param array $data
     * @return int|null Role ID if created, null on failure
     */
    public function createRole(array $data): ?int
    {
        try {
            DB::beginTransaction();

            $roleId = DB::table($this->rolesTable)->insertGetId([
                'name' => $data['name'],
                'slug' => \Str::slug($data['name']),
                'description' => $data['description'] ?? null,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            if (!empty($data['permissions'])) {
                $this->assignPermissionsToRole($roleId, $data['permissions']);
            }

            $this->clearRoleCache();
            DB::commit();

            return $roleId;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to create role: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Update role
     *
     * @param int $roleId
     * @param array $data
     * @return bool
     */
    public function updateRole(int $roleId, array $data): bool
    {
        try {
            DB::beginTransaction();

            $updated = DB::table($this->rolesTable)
                ->where('id', $roleId)
                ->update([
                    'name' => $data['name'],
                    'slug' => \Str::slug($data['name']),
                    'description' => $data['description'] ?? null,
                    'updated_at' => now()
                ]) > 0;

            if (isset($data['permissions'])) {
                // Clear existing permissions
                DB::table($this->rolePermissionsTable)
                    ->where('role_id', $roleId)
                    ->delete();

                // Assign new permissions
                $this->assignPermissionsToRole($roleId, $data['permissions']);
            }

            $this->clearRoleCache();
            DB::commit();

            return $updated;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to update role: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete role
     *
     * @param int $roleId
     * @return bool
     */
    public function deleteRole(int $roleId): bool
    {
        try {
            DB::beginTransaction();

            // Remove role permissions
            DB::table($this->rolePermissionsTable)
                ->where('role_id', $roleId)
                ->delete();

            // Remove user roles
            DB::table($this->userRolesTable)
                ->where('role_id', $roleId)
                ->delete();

            // Delete role
            $deleted = DB::table($this->rolesTable)
                ->where('id', $roleId)
                ->delete() > 0;

            $this->clearRoleCache();
            DB::commit();

            return $deleted;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to delete role: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get role by ID
     *
     * @param int $roleId
     * @return array|null
     */
    public function getRole(int $roleId): ?array
    {
        try {
            $role = DB::table($this->rolesTable)
                ->where('id', $roleId)
                ->first();

            if ($role) {
                $role = (array) $role;
                $role['permissions'] = $this->getRolePermissions($roleId);
            }

            return $role;
        } catch (\Exception $e) {
            \Log::error('Failed to get role: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get role by slug
     *
     * @param string $slug
     * @return array|null
     */
    public function getRoleBySlug(string $slug): ?array
    {
        try {
            $role = DB::table($this->rolesTable)
                ->where('slug', $slug)
                ->first();

            if ($role) {
                $role = (array) $role;
                $role['permissions'] = $this->getRolePermissions($role['id']);
            }

            return $role;
        } catch (\Exception $e) {
            \Log::error('Failed to get role by slug: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get paginated roles
     *
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getPaginatedRoles(int $perPage = 20): LengthAwarePaginator
    {
        return DB::table($this->rolesTable)
            ->orderBy('name')
            ->paginate($perPage);
    }

    /**
     * Get all roles
     *
     * @return Collection
     */
    public function getAllRoles(): Collection
    {
        return collect(DB::table($this->rolesTable)
            ->orderBy('name')
            ->get());
    }

    /**
     * Assign role to user
     *
     * @param int $userId
     * @param int $roleId
     * @return bool
     */
    public function assignRoleToUser(int $userId, int $roleId): bool
    {
        try {
            if (!$this->userHasRole($userId, $roleId)) {
                DB::table($this->userRolesTable)->insert([
                    'user_id' => $userId,
                    'role_id' => $roleId,
                    'created_at' => now()
                ]);
                $this->clearUserRoleCache($userId);
            }
            return true;
        } catch (\Exception $e) {
            \Log::error('Failed to assign role to user: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove role from user
     *
     * @param int $userId
     * @param int $roleId
     * @return bool
     */
    public function removeRoleFromUser(int $userId, int $roleId): bool
    {
        try {
            $removed = DB::table($this->userRolesTable)
                ->where('user_id', $userId)
                ->where('role_id', $roleId)
                ->delete() > 0;

            if ($removed) {
                $this->clearUserRoleCache($userId);
            }

            return $removed;
        } catch (\Exception $e) {
            \Log::error('Failed to remove role from user: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get user roles
     *
     * @param int $userId
     * @return Collection
     */
    public function getUserRoles(int $userId): Collection
    {
        return Cache::remember("user_roles_{$userId}", 3600, function() use ($userId) {
            return collect(DB::table($this->rolesTable)
                ->join($this->userRolesTable, 'roles.id', '=', 'user_roles.role_id')
                ->where('user_roles.user_id', $userId)
                ->select('roles.*')
                ->get());
        });
    }

    /**
     * Check if user has role
     *
     * @param int $userId
     * @param int $roleId
     * @return bool
     */
    public function userHasRole(int $userId, int $roleId): bool
    {
        return DB::table($this->userRolesTable)
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->exists();
    }

    /**
     * Get role permissions
     *
     * @param int $roleId
     * @return Collection
     */
    protected function getRolePermissions(int $roleId): Collection
    {
        return collect(DB::table($this->permissionsTable)
            ->join($this->rolePermissionsTable, 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('role_permissions.role_id', $roleId)
            ->select('permissions.*')
            ->get());
    }

    /**
     * Assign permissions to role
     *
     * @param int $roleId
     * @param array $permissionIds
     * @return void
     */
    protected function assignPermissionsToRole(int $roleId, array $permissionIds): void
    {
        $data = array_map(function($permissionId) use ($roleId) {
            return [
                'role_id' => $roleId,
                'permission_id' => $permissionId,
                'created_at' => now()
            ];
        }, $permissionIds);

        DB::table($this->rolePermissionsTable)->insert($data);
    }

    /**
     * Clear role cache
     *
     * @return void
     */
    protected function clearRoleCache(): void
    {
        Cache::tags(['roles'])->flush();
    }

    /**
     * Clear user role cache
     *
     * @param int $userId
     * @return void
     */
    protected function clearUserRoleCache(int $userId): void
    {
        Cache::forget("user_roles_{$userId}");
    }
}
