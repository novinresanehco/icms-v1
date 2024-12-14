<?php

namespace App\Core\Security\Authorization;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Core\Security\Validation\ValidationService;
use App\Core\Audit\AuditLogger;
use App\Exceptions\AuthorizationException;

class RBACManager implements AuthorizationInterface
{
    private ValidationService $validator;
    private AuditLogger $auditLogger;
    private array $config;

    private const PERMISSION_CACHE_TTL = 300; // 5 minutes
    private const PERMISSION_CACHE_PREFIX = 'rbac_perms:';
    private const ROLE_CACHE_PREFIX = 'rbac_role:';

    public function checkPermission(User $user, string $permission, array $context = []): bool
    {
        DB::beginTransaction();
        
        try {
            // Validate inputs
            $this->validator->validatePermissionCheck($user, $permission, $context);

            // Get user's effective permissions
            $permissions = $this->getUserPermissions($user);

            // Check specific permission
            $hasPermission = $this->evaluatePermission($permissions, $permission, $context);
            
            // Log access check
            $this->auditLogger->logPermissionCheck($user->id, $permission, $hasPermission);

            DB::commit();
            
            return $hasPermission;

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->auditLogger->logAuthorizationFailure($user->id, $permission, $e);
            throw new AuthorizationException('Permission check failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function enforcePermission(User $user, string $permission, array $context = []): void
    {
        if (!$this->checkPermission($user, $permission, $context)) {
            $this->auditLogger->logUnauthorizedAccess($user->id, $permission, $context);
            throw new AuthorizationException("Access denied: Missing permission '$permission'");
        }
    }

    public function getUserPermissions(User $user): array
    {
        return Cache::remember(
            self::PERMISSION_CACHE_PREFIX . $user->id,
            self::PERMISSION_CACHE_TTL,
            function() use ($user) {
                return $this->calculateEffectivePermissions($user);
            }
        );
    }

    private function calculateEffectivePermissions(User $user): array
    {
        $permissions = [];
        
        // Get role-based permissions
        $rolePermissions = $this->getRolePermissions($user->roles);
        $permissions = array_merge($permissions, $rolePermissions);

        // Get direct user permissions
        $userPermissions = $this->getDirectPermissions($user);
        $permissions = array_merge($permissions, $userPermissions);

        // Get inherited permissions
        $inheritedPermissions = $this->getInheritedPermissions($permissions);
        $permissions = array_merge($permissions, $inheritedPermissions);

        // Apply permission rules and constraints
        return $this->applyPermissionRules($permissions, $user);
    }

    private function getRolePermissions(Collection $roles): array
    {
        $permissions = [];
        
        foreach ($roles as $role) {
            $rolePerms = Cache::remember(
                self::ROLE_CACHE_PREFIX . $role->id,
                self::PERMISSION_CACHE_TTL,
                function() use ($role) {
                    return $role->permissions()
                        ->with('constraints')
                        ->get()
                        ->toArray();
                }
            );
            
            $permissions = array_merge($permissions, $rolePerms);
        }

        return $permissions;
    }

    private function getDirectPermissions(User $user): array
    {
        return $user->directPermissions()
            ->with('constraints')
            ->get()
            ->toArray();
    }

    private function getInheritedPermissions(array $basePermissions): array
    {
        $inherited = [];
        
        foreach ($basePermissions as $permission) {
            $impliedPerms = $this->getImpliedPermissions($permission);
            $inherited = array_merge($inherited, $impliedPerms);
        }

        return $inherited;
    }

    private function getImpliedPermissions(array $permission): array
    {
        return Cache::remember(
            'perm_implied:' . $permission['id'],
            self::PERMISSION_CACHE_TTL,
            function() use ($permission) {
                return Permission::where('implied_by', $permission['id'])
                    ->with('constraints')
                    ->get()
                    ->toArray();
            }
        );
    }

    private function applyPermissionRules(array $permissions, User $user): array
    {
        $effectivePermissions = [];
        
        foreach ($permissions as $permission) {
            if ($this->validatePermissionRules($permission, $user)) {
                $effectivePermissions[] = $permission;
            }
        }

        return $effectivePermissions;
    }

    private function validatePermissionRules(array $permission, User $user): bool
    {
        // Check temporal constraints
        if (!$this->checkTemporalConstraints($permission)) {
            return false;
        }

        // Check contextual constraints
        if (!$this->checkContextualConstraints($permission, $user)) {
            return false;
        }

        // Check environmental constraints
        if (!$this->checkEnvironmentalConstraints($permission)) {
            return false;
        }

        return true;
    }

    private function evaluatePermission(array $permissions, string $permission, array $context): bool
    {
        foreach ($permissions as $userPermission) {
            if ($this->permissionMatches($userPermission, $permission)) {
                if ($this->evaluateConstraints($userPermission, $context)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function permissionMatches(array $userPermission, string $requestedPermission): bool
    {
        return $userPermission['name'] === $requestedPermission || 
               $this->matchesWildcard($userPermission['name'], $requestedPermission);
    }

    private function matchesWildcard(string $pattern, string $subject): bool
    {
        $pattern = preg_quote($pattern, '/');
        $pattern = str_replace('\*', '.*', $pattern);
        return (bool) preg_match('/^' . $pattern . '$/', $subject);
    }

    private function evaluateConstraints(array $permission, array $context): bool
    {
        foreach ($permission['constraints'] as $constraint) {
            if (!$this->evaluateConstraint($constraint, $context)) {
                return false;
            }
        }
        return true;
    }

    private function checkTemporalConstraints(array $permission): bool
    {
        $now = time();
        
        // Check validity period
        if (isset($permission['valid_from']) && $now < $permission['valid_from']) {
            return false;
        }
        
        if (isset($permission['valid_until']) && $now > $permission['valid_until']) {
            return false;
        }

        // Check time-of-day restrictions
        if (isset($permission['time_restrictions'])) {
            return $this->validateTimeRestrictions($permission['time_restrictions']);
        }

        return true;
    }

    private function checkContextualConstraints(array $permission, User $user): bool
    {
        // Check user attributes
        if (isset($permission['user_constraints'])) {
            if (!$this->validateUserConstraints($permission['user_constraints'], $user)) {
                return false;
            }
        }

        // Check organization constraints
        if (isset($permission['org_constraints'])) {
            if (!$this->validateOrgConstraints($permission['org_constraints'], $user)) {
                return false;
            }
        }

        return true;
    }

    private function checkEnvironmentalConstraints(array $permission): bool
    {
        // Check IP restrictions
        if (isset($permission['ip_restrictions'])) {
            if (!$this->validateIpRestrictions($permission['ip_restrictions'])) {
                return false;
            }
        }

        // Check environment restrictions
        if (isset($permission['env_restrictions'])) {
            if (!$this->validateEnvRestrictions($permission['env_restrictions'])) {
                return false;
            }
        }

        return true;
    }
}
