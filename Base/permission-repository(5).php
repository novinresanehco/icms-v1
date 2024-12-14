<?php

namespace App\Repositories;

use App\Repositories\Contracts\PermissionRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class PermissionRepository implements PermissionRepositoryInterface
{
    protected string $permissionsTable = 'permissions';
    protected string $rolePermissionsTable = 'role_permissions';
    protected string $userPermissionsTable = 'user_permissions';

    public function createPermission(array $data): ?int
    {
        try {
            DB::beginTransaction();

            $permissionId = DB::table($this->permissionsTable)->insertGetId([
                'name' => $data['name'],
                'slug' => \Str::slug($data['name']),
                'description' => $data['description'] ?? null,
                'group' => $data['group'] ?? null,
                'created_at' => now(),
                'updated_at' => now()
            ]);

            $this->clearPermissionCache();
            DB::commit();

            return $permissionId;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to create permission: ' . $e->getMessage());
            return null;
        }
    }

    public function updatePermission(int $permissionId, array $data): bool
    {
        try {
            $updated = DB::table($this->permissionsTable)
                ->where('id', $permissionId)
                ->update([
                    'name' => $data['name'],
                    'slug' => \Str::slug($data['name']),
                    'description' => $data['description'] ?? null,
                    'group' => $data['group'] ?? null,
                    'updated_at' => now()
                ]) > 0;

            if ($updated) {
                $this->clearPermissionCache();
            }

            return $updated;
        } catch (\Exception $e) {
            \Log::error('Failed to update permission: ' . $e->getMessage());
            return false;
        }
    }

    public function deletePermission(int $permissionId): bool
    {
        try {
            DB::beginTransaction();

            // Remove from roles
            DB::table($this->rolePermissionsTable)
                ->where('permission_id', $permissionId)
                ->delete();

            // Remove from users
            DB::table($this->userPermissionsTable)
                ->where('permission_id', $permissionId)
                ->delete();

            // Delete permission
            $deleted = DB::table($this->permissionsTable)
                ->where('id', $permissionId)
                ->delete() > 0;

            if ($deleted) {
                $this->clearPermissionCache();
            }

            DB::commit();
            return $deleted;
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Failed to delete permission: ' . $e->getMessage());
            return false;
        }
    }

    public function getPermission(int $permissionId): ?array
    {
        try {
            $permission = DB::table($this->permissionsTable)
                ->where('id', $permissionId)
                ->first();

            return $permission ? (array) $permission : null;
        } catch (\Exception $e) {
            \Log::error('Failed to get permission: ' . $e->getMessage());
            return null;
        }
    }

    public function getPermissionBySlug(string $slug): ?array
    {
        try {
            $permission = DB::table($this->permissionsTable)
                ->where('slug', $slug)
                ->first();

            return $permission ? (array) $permission : null;
        } catch (\Exception $e) {
            \Log::error('Failed to get permission by slug: ' . $e->getMessage());
            return null;
        }
    }

    public function getAllPermissions(): Collection
    {
        return Cache::remember('all_permissions', 3600, function() {
            return collect(DB::table($this->permissionsTable)
                ->orderBy('group')
                ->orderBy('name')
                ->get());
        });
    }

    public function getPermissionsByGroup(string $group): Collection
    {
        return $this->getAllPermissions()
            ->where('group', $group);
    }

    public function getUserPermissions(int $userId): Collection
    {
        return Cache::remember("user_permissions_{$userId}", 3600, function() use ($userId) {
            return collect(DB::table($this->permissionsTable)
                ->join($this->userPermissionsTable, 'permissions.id', '=', 'user_permissions.permission_id')
                ->where('user_permissions.user_id', $userId)
                ->select('permissions.*')
                ->get());
        });
    }

    public function assignDirectPermission(int $userId, int $permissionId): bool
    {
        try {
            if (!$this->userHasDirectPermission($userId, $permissionId)) {
                DB::table($this->userPermissionsTable)->insert([
                    'user_id' => $userId,
                    'permission_id' => $permissionId,
                    'created_at' => now()
                ]);
                $this->clearUserPermissionCache($userId);
            }
            return true;
        } catch (\Exception $e) {
            \Log::error('Failed to assign direct permission: ' . $e->getMessage());
            return false;
        }
    }

    public function removeDirectPermission(int $userId, int $permissionId): bool
    {
        try {
            $removed = DB::table($this->userPermissionsTable)
                ->where('user_id', $userId)
                ->where('permission_id', $permissionId)
                ->delete() > 0;

            if ($removed) {
                $this->clearUserPermissionCache($userId);
            }

            return $removed;
        } catch (\Exception $e) {
            \Log::error('Failed to remove direct permission: ' . $e->getMessage());
            return false;
        }
    }

    public function userHasDirectPermission(int $userId, int $permissionId): bool
    {
        return DB::table($this->userPermissionsTable)
            ->where('user_id', $userId)
            ->where('permission_id', $permissionId)
            ->exists();
    }

    protected function clearPermissionCache(): void
    {
        Cache::forget('all_permissions');
        Cache::tags(['permissions'])->flush();
    }

    protected function clearUserPermissionCache(int $userId): void
    {
        Cache::forget("user_permissions_{$userId}");
    }
}
