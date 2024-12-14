<?php

namespace App\Core\Security\RBAC;

use Illuminate\Support\Facades\{DB, Cache};

class RBACManager
{
    private RoleRepository $roles;
    private PermissionRepository $permissions;
    private CacheManager $cache;
    private AuditLogger $audit;

    public function __construct(
        RoleRepository $roles,
        PermissionRepository $permissions,
        CacheManager $cache,
        AuditLogger $audit
    ) {
        $this->roles = $roles;
        $this->permissions = $permissions;
        $this->cache = $cache;
        $this->audit = $audit;
    }

    public function validateAccess(int $userId, string $permission): bool
    {
        return $this->cache->remember(
            "access.{$userId}.{$permission}",
            fn() => $this->checkAccess($userId, $permission)
        );
    }

    public function checkAccess(int $userId, string $permission): bool
    {
        DB::beginTransaction();
        try {
            $userRoles = $this->roles->getUserRoles($userId);
            $hasAccess = $this->permissions->checkPermission($userRoles, $permission);
            
            $this->audit->logAccessCheck($userId, $permission, $hasAccess);
            
            DB::commit();
            return $hasAccess;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->audit->logError('access_check_failed', $e);
            throw $e;
        }
    }

    public function assignRole(int $userId, string $roleId): void
    {
        DB::beginTransaction();
        try {
            $this->roles->assignRole($userId, $roleId);
            $this->cache->invalidateUserAccess($userId);
            $this->audit->logRoleAssignment($userId, $roleId);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            $this->audit->logError('role_assignment_failed', $e);
            throw $e;
        }
    }

    public function createRole(string $name, array $permissions): Role
    {
        DB::beginTransaction();
        try {
            $role = $this->roles->create($name);
            $this->permissions->assignToRole($role->id, $permissions);
            
            $this->audit->logRoleCreation($role->id);
            $this->cache->invalidateRoles();
            
            DB::commit();
            return $role;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->audit->logError('role_creation_failed', $e);
            throw $e;
        }
    }
}

class RoleRepository
{
    public function getUserRoles(int $userId): array
    {
        return DB::table('user_roles')
            ->where('user_id', $userId)
            ->pluck('role_id')
            ->all();
    }

    public function assignRole(int $userId, string $roleId): void
    {
        DB::table('user_roles')->insert([
            'user_id' => $userId,
            'role_id' => $roleId
        ]);
    }

    public function create(string $name): Role
    {
        $id = DB::table('roles')->insertGetId([
            'name' => $name
        ]);
        return new Role($id, $name);
    }
}

class PermissionRepository
{
    public function checkPermission(array $roles, string $permission): bool
    {
        return DB::table('role_permissions')
            ->whereIn('role_id', $roles)
            ->where('permission', $permission)
            ->exists();
    }

    public function assignToRole(string $roleId, array $permissions): void
    {
        $data = array_map(
            fn($permission) => [
                'role_id' => $roleId,
                'permission' => $permission
            ],
            $permissions
        );

        DB::table('role_permissions')->insert($data);
    }
}

class CacheManager
{
    public function remember(string $key, callable $callback): mixed
    {
        return Cache::remember($key, 3600, $callback);
    }

    public function invalidateUserAccess(int $userId): void
    {
        Cache::tags(['access', "user.$userId"])->flush();
    }

    public function invalidateRoles(): void
    {
        Cache::tags(['roles'])->flush();
    }
}

class AuditLogger
{
    public function logAccessCheck(int $userId, string $permission, bool $granted): void
    {
        $this->log('access_check', [
            'user_id' => $userId,
            'permission' => $permission,
            'granted' => $granted
        ]);
    }

    public function logRoleAssignment(int $userId, string $roleId): void
    {
        $this->log('role_assignment', [
            'user_id' => $userId,
            'role_id' => $roleId
        ]);
    }

    public function logRoleCreation(string $roleId): void
    {
        $this->log('role_creation', [
            'role_id' => $roleId
        ]);
    }

    public function logError(string $type, \Exception $error): void
    {
        $this->log('error', [
            'type' => $type,
            'message' => $error->getMessage(),
            'trace' => $error->getTraceAsString()
        ]);
    }

    private function log(string $type, array $data): void
    {
        DB::table('security_audit_log')->insert([
            'type' => $type,
            'data' => json_encode($data),
            'created_at' => now()
        ]);
    }
}

class Role
{
    public function __construct(
        public readonly string $id,
        public readonly string $name
    ) {}
}