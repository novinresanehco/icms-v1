<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{Cache, DB, Log};
use App\Core\Security\SecurityManager;
use App\Core\Services\{ValidationService, AuditService};
use App\Core\Exceptions\{AuthorizationException, ValidationException};

class RBACManager implements AccessControlInterface 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private AuditService $audit;
    private array $config;

    private const CACHE_TTL = 3600; // 1 hour
    private const MAX_ROLE_DEPTH = 5;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        AuditService $audit,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function checkPermission(int $userId, string $permission): bool 
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executePermissionCheck($userId, $permission),
            ['action' => 'rbac.check_permission', 'user_id' => $userId, 'permission' => $permission]
        );
    }

    protected function executePermissionCheck(int $userId, string $permission): bool 
    {
        $cacheKey = "user.{$userId}.permission.{$permission}";
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function() use ($userId, $permission) {
            $user = User::with('roles.permissions')->findOrFail($userId);
            
            $hasPermission = $user->roles->flatMap(function($role) {
                return $this->getRolePermissions($role);
            })->contains('name', $permission);

            $this->audit->log('rbac.permission_check', [
                'user_id' => $userId,
                'permission' => $permission,
                'granted' => $hasPermission
            ]);

            return $hasPermission;
        });
    }

    public function assignRole(int $userId, int $roleId): void 
    {
        $this->security->executeCriticalOperation(
            fn() => $this->executeRoleAssignment($userId, $roleId),
            ['action' => 'rbac.assign_role', 'user_id' => $userId, 'role_id' => $roleId]
        );
    }

    protected function executeRoleAssignment(int $userId, int $roleId): void 
    {
        DB::beginTransaction();
        try {
            $user = User::findOrFail($userId);
            $role = Role::findOrFail($roleId);

            if ($user->roles->contains($roleId)) {
                throw new AuthorizationException('Role already assigned');
            }

            $user->roles()->attach($roleId);
            
            $this->clearUserPermissionCache($userId);

            DB::commit();

            $this->audit->log('rbac.role_assigned', [
                'user_id' => $userId,
                'role_id' => $roleId
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            throw new AuthorizationException('Failed to assign role: ' . $e->getMessage());
        }
    }

    public function createRole(array $data): Role 
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeRoleCreation($data),
            ['action' => 'rbac.create_role', 'data' => $data]
        );
    }

    protected function executeRoleCreation(array $data): Role 
    {
        $validated = $this->validator->validate($data, [
            'name' => 'required|string|max:50|unique:roles',
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,id'
        ]);

        DB::beginTransaction();
        try {
            $role = Role::create([
                'name' => $validated['name']
            ]);

            if (!empty($validated['permissions'])) {
                $role->permissions()->attach($validated['permissions']);
            }

            DB::commit();

            $this->audit->log('rbac.role_created', [
                'role_id' => $role->id,
                'permissions' => $validated['permissions'] ?? []
            ]);

            return $role->load('permissions');

        } catch (\Exception $e) {
            DB::rollBack();
            throw new AuthorizationException('Failed to create role: ' . $e->getMessage());
        }
    }

    public function createPermission(array $data): Permission 
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executePermissionCreation($data),
            ['action' => 'rbac.create_permission', 'data' => $data]
        );
    }

    protected function executePermissionCreation(array $data): Permission 
    {
        $validated = $this->validator->validate($data, [
            'name' => 'required|string|max:50|unique:permissions',
            'description' => 'string|max:255'
        ]);

        DB::beginTransaction();
        try {
            $permission = Permission::create($validated);

            DB::commit();

            $this->audit->log('rbac.permission_created', [
                'permission_id' => $permission->id
            ]);

            return $permission;

        } catch (\Exception $e) {
            DB::rollBack();
            throw new AuthorizationException('Failed to create permission: ' . $e->getMessage());
        }
    }

    protected function getRolePermissions(Role $role, int $depth = 0): \Illuminate\Support\Collection 
    {
        if ($depth >= self::MAX_ROLE_DEPTH) {
            Log::warning('Max role depth reached', ['role_id' => $role->id]);
            return collect();
        }

        $permissions = $role->permissions;

        foreach ($role->parentRoles as $parentRole) {
            $permissions = $permissions->merge(
                $this->getRolePermissions($parentRole, $depth + 1)
            );
        }

        return $permissions->unique('id');
    }

    protected function clearUserPermissionCache(int $userId): void 
    {
        $user = User::with('roles.permissions')->find($userId);
        
        if (!$user) return;

        foreach ($user->roles as $role) {
            foreach ($role->permissions as $permission) {
                Cache::forget("user.{$userId}.permission.{$permission->name}");
            }
        }
    }

    public function validateRoleHierarchy(int $roleId, int $parentRoleId): bool 
    {
        if ($roleId === $parentRoleId) {
            return false;
        }

        $visited = [$roleId];
        $queue = [$parentRoleId];

        while (!empty($queue)) {
            $currentId = array_shift($queue);
            
            if (in_array($currentId, $visited)) {
                return false;
            }

            $visited[] = $currentId;

            $parentIds = Role::find($currentId)
                ->parentRoles()
                ->pluck('id')
                ->toArray();

            $queue = array_merge($queue, $parentIds);
        }

        return true;
    }
}
