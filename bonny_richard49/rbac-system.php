<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{Cache, DB};
use App\Core\Security\{SecurityConfig, SecurityException};
use App\Core\Interfaces\AccessControlInterface;

class AccessControlManager implements AccessControlInterface
{
    private SecurityConfig $config;
    private AuditLogger $auditLogger;
    private PermissionRegistry $permissions;
    private RoleRepository $roleRepository;

    public function __construct(
        SecurityConfig $config,
        AuditLogger $auditLogger,
        PermissionRegistry $permissions,
        RoleRepository $roleRepository
    ) {
        $this->config = $config;
        $this->auditLogger = $auditLogger;
        $this->permissions = $permissions;
        $this->roleRepository = $roleRepository;
    }

    public function hasPermission(User $user, string $permission, ?array $context = null): bool
    {
        try {
            // Check if user is super admin
            if ($this->isSuperAdmin($user)) {
                return true;
            }

            // Get user's roles and cached permissions
            $roles = $this->getUserRoles($user);
            $permissions = $this->getRolePermissions($roles);

            // Direct permission check
            if (in_array($permission, $permissions)) {
                return $this->validateContextualPermission($user, $permission, $context);
            }

            // Check wildcards and inheritance
            foreach ($permissions as $rolePermission) {
                if ($this->matchesWildcard($rolePermission, $permission)) {
                    return $this->validateContextualPermission($user, $permission, $context);
                }
            }

            // Log unauthorized access attempt
            $this->auditLogger->logUnauthorizedAccess($user->id, $permission);
            
            return false;

        } catch (\Exception $e) {
            $this->handleAccessError($e, 'permission_check', [
                'user_id' => $user->id,
                'permission' => $permission
            ]);
            throw new AccessControlException(
                'Permission check failed',
                previous: $e
            );
        }
    }

    public function assignRole(User $user, string $role): void
    {
        DB::beginTransaction();
        try {
            // Validate role exists
            if (!$this->roleRepository->exists($role)) {
                throw new RoleNotFoundException("Role not found: {$role}");
            }

            // Check for role conflicts
            if ($this->hasRoleConflict($user, $role)) {
                throw new RoleConflictException("Role conflict detected");
            }

            // Assign role
            $this->roleRepository->assignRole($user->id, $role);

            // Clear permissions cache
            $this->clearUserPermissionsCache($user->id);

            // Log role assignment
            $this->auditLogger->logRoleAssignment($user->id, $role);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAccessError($e, 'role_assignment', [
                'user_id' => $user->id,
                'role' => $role
            ]);
            throw new RoleAssignmentException(
                'Failed to assign role',
                previous: $e
            );
        }
    }

    public function removeRole(User $user, string $role): void
    {
        DB::beginTransaction();
        try {
            // Remove role
            $this->roleRepository->removeRole($user->id, $role);

            // Clear permissions cache
            $this->clearUserPermissionsCache($user->id);

            // Log role removal
            $this->auditLogger->logRoleRemoval($user->id, $role);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAccessError($e, 'role_removal', [
                'user_id' => $user->id,
                'role' => $role
            ]);
            throw new RoleRemovalException(
                'Failed to remove role',
                previous: $e
            );
        }
    }

    public function createRole(string $name, array $permissions): void
    {
        DB::beginTransaction();
        try {
            // Validate permissions exist
            foreach ($permissions as $permission) {
                if (!$this->permissions->exists($permission)) {
                    throw new PermissionNotFoundException("Permission not found: {$permission}");
                }
            }

            // Create role
            $this->roleRepository->create([
                'name' => $name,
                'permissions' => $permissions,
                'created_at' => now()
            ]);

            // Log role creation
            $this->auditLogger->logRoleCreation($name, $permissions);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAccessError($e, 'role_creation', [
                'name' => $name,
                'permissions' => $permissions
            ]);
            throw new RoleCreationException(
                'Failed to create role',
                previous: $e
            );
        }
    }

    public function updateRole(string $name, array $permissions): void
    {
        DB::beginTransaction();
        try {
            // Update role permissions
            $this->roleRepository->update($name, [
                'permissions' => $permissions,
                'updated_at' => now()
            ]);

            // Clear all affected users' permission cache
            $this->clearRolePermissionsCache($name);

            // Log role update
            $this->auditLogger->logRoleUpdate($name, $permissions);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAccessError($e, 'role_update', [
                'name' => $name,
                'permissions' => $permissions
            ]);
            throw new RoleUpdateException(
                'Failed to update role',
                previous: $e
            );
        }
    }

    private function getUserRoles(User $user): array
    {
        return Cache::remember(
            "user_roles:{$user->id}",
            $this->config->getCacheTTL(),
            fn() => $this->roleRepository->getUserRoles($user->id)
        );
    }

    private function getRolePermissions(array $roles): array
    {
        $permissions = [];
        foreach ($roles as $role) {
            $rolePermissions = Cache::remember(
                "role_permissions:{$role}",
                $this->config->getCacheTTL(),
                fn() => $this->roleRepository->getRolePermissions($role)
            );
            $permissions = array_merge($permissions, $rolePermissions);
        }
        return array_unique($permissions);
    }

    private function validateContextualPermission(User $user, string $permission, ?array $context): bool
    {
        if (!$context) {
            return true;
        }

        // Implement contextual permission logic
        // This could check things like department, resource ownership, etc.
        return $this->permissions->validateContext($user, $permission, $context);
    }

    private function matchesWildcard(string $rolePermission, string $requiredPermission): bool
    {
        // Convert wildcard pattern to regex
        $pattern = '/^' . str_replace('*', '.*', $rolePermission) . '$/';
        return (bool)preg_match($pattern, $requiredPermission);
    }

    private function hasRoleConflict(User $user, string $newRole): bool
    {
        $conflictingRoles = $this->config->getConflictingRoles();
        $userRoles = $this->getUserRoles($user);

        foreach ($userRoles as $existingRole) {
            if (isset($conflictingRoles[$existingRole]) && 
                in_array($newRole, $conflictingRoles[$existingRole])) {
                return true;
            }
        }

        return false;
    }

    private function isSuperAdmin(User $user): bool
    {
        return in_array('super_admin', $this->getUserRoles($user));
    }

    private function clearUserPermissionsCache(int $userId): void
    {
        Cache::forget("user_roles:{$userId}");
    }

    private function clearRolePermissionsCache(string $role): void
    {
        Cache::forget("role_permissions:{$role}");
    }

    private function handleAccessError(\Exception $e, string $operation, array $context): void
    {
        $this->auditLogger->logAccessError($e, $operation, $context);
    }
}
