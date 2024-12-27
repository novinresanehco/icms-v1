<?php

namespace App\Core\Security;

class AccessControl implements AccessControlInterface 
{
    private RoleManager $roles;
    private PermissionRegistry $permissions;
    private AuditLogger $audit;
    private ResourceManager $resources;

    public function __construct(
        RoleManager $roles,
        PermissionRegistry $permissions,
        AuditLogger $audit,
        ResourceManager $resources
    ) {
        $this->roles = $roles;
        $this->permissions = $permissions;
        $this->audit = $audit;
        $this->resources = $resources;
    }

    public function hasPermission(User $user, string $permission): bool
    {
        try {
            $role = $this->roles->getUserRole($user);
            return $this->permissions->checkPermission($role, $permission);
        } catch (\Exception $e) {
            $this->audit->logAccessFailure($user, $permission, $e);
            return false;
        }
    }

    public function canAccess(User $user, Model $resource): bool
    {
        try {
            return $this->resources->validateAccess($user, $resource);
        } catch (\Exception $e) {
            $this->audit->logResourceAccessFailure($user, $resource, $e);
            return false;
        }
    }

    public function validatePermissions(User $user): bool
    {
        try {
            return $this->roles->validateUserPermissions($user);
        } catch (\Exception $e) {
            $this->audit->logPermissionValidationFailure($user, $e);
            return false;
        }
    }

    public function validateResourceAccess(SecurityContext $context): bool
    {
        try {
            return $this->resources->validateContext($context);
        } catch (\Exception $e) {
            $this->audit->logContextValidationFailure($context, $e);
            return false;
        }
    }
}
