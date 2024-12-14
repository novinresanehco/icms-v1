<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{Cache, DB};
use App\Core\Security\SecurityManager;
use App\Core\Auth\Events\AuthorizationEvent;

class RBACManager implements AuthorizationInterface 
{
    private SecurityManager $security;
    private AuditLogger $auditLogger;
    private PermissionRegistry $permissions;
    private RoleRepository $roles;

    public function __construct(
        SecurityManager $security,
        AuditLogger $auditLogger,
        PermissionRegistry $permissions,
        RoleRepository $roles
    ) {
        $this->security = $security;
        $this->auditLogger = $auditLogger;
        $this->permissions = $permissions;
        $this->roles = $roles;
    }

    public function checkPermission(User $user, string $permission, ?Resource $resource = null): bool 
    {
        return DB::transaction(function() use ($user, $permission, $resource) {
            try {
                // Cache permission check results
                $cacheKey = "permission:{$user->id}:{$permission}";
                
                return Cache::remember($cacheKey, 300, function() use ($user, $permission, $resource) {
                    // Verify user account status
                    $this->verifyUserStatus($user);

                    // Check role-based permissions
                    $hasPermission = $this->validateRolePermission($user, $permission);
                    
                    // Additional resource-specific checks
                    if ($resource && $hasPermission) {
                        $hasPermission = $this->validateResourceAccess($user, $resource, $permission);
                    }

                    // Log access check
                    $this->auditLogger->logPermissionCheck($user, $permission, $hasPermission);

                    return $hasPermission;
                });

            } catch (AuthorizationException $e) {
                $this->handleAuthorizationFailure($e, $user, $permission);
                throw $e;
            }
        });
    }

    public function validateRolePermission(User $user, string $permission): bool 
    {
        // Super admin check
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        // Get user roles with cache
        $roles = $this->getUserRoles($user);

        // Check permission in roles
        foreach ($roles as $role) {
            if ($this->roleHasPermission($role, $permission)) {
                return true;
            }
        }

        return false;
    }

    private function validateResourceAccess(User $user, Resource $resource, string $permission): bool 
    {
        // Verify ownership if applicable
        if ($resource->requiresOwnership()) {
            if (!$this->verifyResourceOwnership($user, $resource)) {
                $this->auditLogger->logUnauthorizedAccess($user, $resource);
                return false;
            }
        }

        // Check resource-specific permissions
        return $this->permissions->checkResourcePermission($user, $resource, $permission);
    }

    private function verifyUserStatus(User $user): void 
    {
        if ($user->isSuspended()) {
            throw new AccountSuspendedException('Account is suspended');
        }

        if ($user->requiresReauthorization()) {
            throw new ReauthorizationRequiredException('Reauthorization required');
        }
    }

    private function getUserRoles(User $user): array 
    {
        $cacheKey = "user_roles:{$user->id}";
        
        return Cache::remember($cacheKey, 300, function() use ($user) {
            return $this->roles->getUserRoles($user);
        });
    }

    private function roleHasPermission(Role $role, string $permission): bool 
    {
        $cacheKey = "role_permission:{$role->id}:{$permission}";
        
        return Cache::remember($cacheKey, 300, function() use ($role, $permission) {
            return $this->permissions->roleHasPermission($role, $permission);
        });
    }

    private function isSuperAdmin(User $user): bool 
    {
        $cacheKey = "super_admin:{$user->id}";
        
        return Cache::remember($cacheKey, 300, function() use ($user) {
            return $user->isSuperAdmin();
        });
    }

    private function verifyResourceOwnership(User $user, Resource $resource): bool 
    {
        return $resource->isOwnedBy($user);
    }

    public function assignRole(User $user, Role $role): void 
    {
        DB::transaction(function() use ($user, $role) {
            // Verify role assignment is allowed
            $this->validateRoleAssignment($user, $role);

            // Assign role
            $this->roles->assignRole($user, $role);

            // Clear relevant caches
            $this->clearUserCaches($user);

            // Log role assignment
            $this->auditLogger->logRoleAssignment($user, $role);
        });
    }

    private function validateRoleAssignment(User $user, Role $role): void 
    {
        // Check for role conflicts
        if ($this->hasConflictingRoles($user, $role)) {
            throw new RoleConflictException('Role assignment creates a conflict');
        }

        // Verify role hierarchy
        if (!$this->validateRoleHierarchy($user, $role)) {
            throw new InvalidRoleAssignmentException('Invalid role hierarchy');
        }
    }

    private function hasConflictingRoles(User $user, Role $role): bool 
    {
        $existingRoles = $this->getUserRoles($user);
        return $this->roles->checkConflicts($existingRoles, $role);
    }

    private function validateRoleHierarchy(User $user, Role $role): bool 
    {
        return $this->roles->validateHierarchy($user, $role);
    }

    private function clearUserCaches(User $user): void 
    {
        Cache::forget("user_roles:{$user->id}");
        Cache::forget("super_admin:{$user->id}");
        
        // Clear all permission caches for user
        $this->permissions->clearUserPermissionCache($user);
    }

    private function handleAuthorizationFailure(AuthorizationException $e, User $user, string $permission): void 
    {
        $this->auditLogger->logAuthorizationFailure($e, $user, $permission);

        if ($this->detectSuspiciousAuthorizationAttempt($user, $permission)) {
            event(new AuthorizationEvent('suspicious_authorization_attempt', [
                'user_id' => $user->id,
                'permission' => $permission,
                'timestamp' => now()
            ]));
        }
    }

    private function detectSuspiciousAuthorizationAttempt(User $user, string $permission): bool 
    {
        $key = "auth_attempts:{$user->id}";
        $attempts = Cache::increment($key);
        Cache::put($key, $attempts, now()->addMinutes(15));

        return $attempts >= 10; // 10 failed attempts in 15 minutes is suspicious
    }
}
