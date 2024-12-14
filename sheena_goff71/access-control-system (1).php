<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{DB, Cache};
use App\Core\Security\{SecurityContext, RoleManager};
use App\Core\Services\{ValidationService, AuditService};
use App\Core\Exceptions\{AccessException, SecurityException};

class AccessControlSystem implements AccessControlInterface
{
    private ValidationService $validator;
    private RoleManager $roleManager;
    private AuditService $audit;
    private array $config;

    public function __construct(
        ValidationService $validator,
        RoleManager $roleManager,
        AuditService $audit
    ) {
        $this->validator = $validator;
        $this->roleManager = $roleManager;
        $this->audit = $audit;
        $this->config = config('access');
    }

    public function verifyAccess(string $resource, string $action, SecurityContext $context): bool
    {
        try {
            // Validate request
            $this->validateAccessRequest($resource, $action);

            return DB::transaction(function() use ($resource, $action, $context) {
                // Check permissions
                if (!$this->hasPermission($context->user(), $resource, $action)) {
                    $this->audit->logAccessDenied($resource, $action, $context);
                    return false;
                }

                // Verify constraints
                if (!$this->validateConstraints($resource, $action, $context)) {
                    throw new SecurityException('Access constraints not met');
                }

                // Check security context
                if (!$this->verifySecurityContext($context)) {
                    throw new SecurityException('Invalid security context');
                }

                // Log access
                $this->audit->logAccessGranted($resource, $action, $context);

                return true;
            });

        } catch (\Exception $e) {
            $this->handleAccessFailure($e, $resource, $action, $context);
            throw new AccessException('Access verification failed: ' . $e->getMessage());
        }
    }

    public function grantAccess(string $role, array $permissions, SecurityContext $context): bool
    {
        return DB::transaction(function() use ($role, $permissions, $context) {
            try {
                // Validate role and permissions
                $this->validateRole($role);
                $this->validatePermissions($permissions);

                // Verify authority
                if (!$this->hasGrantAuthority($context)) {
                    throw new SecurityException('Insufficient authority to grant access');
                }

                // Grant permissions
                foreach ($permissions as $permission) {
                    $this->grantPermission($role, $permission, $context);
                }

                // Update role cache
                $this->updateRoleCache($role);

                // Log operation
                $this->audit->logAccessGrant($role, $permissions, $context);

                return true;

            } catch (\Exception $e) {
                $this->handleGrantFailure($e, $role, $permissions, $context);
                throw new AccessException('Access grant failed: ' . $e->getMessage());
            }
        });
    }

    public function revokeAccess(string $role, array $permissions, SecurityContext $context): bool
    {
        return DB::transaction(function() use ($role, $permissions, $context) {
            try {
                // Validate revocation request
                $this->validateRevocation($role, $permissions);

                // Verify authority
                if (!$this->hasRevokeAuthority($context)) {
                    throw new SecurityException('Insufficient authority to revoke access');
                }

                // Revoke permissions
                foreach ($permissions as $permission) {
                    $this->revokePermission($role, $permission, $context);
                }

                // Update role cache
                $this->invalidateRoleCache($role);

                // Log operation
                $this->audit->logAccessRevoke($role, $permissions, $context);

                return true;

            } catch (\Exception $e) {
                $this->handleRevokeFailure($e, $role, $permissions, $context);
                throw new AccessException('Access revocation failed: ' . $e->getMessage());
            }
        });
    }

    private function validateAccessRequest(string $resource, string $action): void
    {
        if (!$this->validator->validateResource($resource)) {
            throw new AccessException('Invalid resource identifier');
        }

        if (!$this->validator->validateAction($action)) {
            throw new AccessException('Invalid action identifier');
        }
    }

    private function hasPermission(User $user, string $resource, string $action): bool
    {
        $roles = $this->roleManager->getUserRoles($user);
        
        foreach ($roles as $role) {
            if ($this->roleHasPermission($role, $resource, $action)) {
                return true;
            }
        }

        return false;
    }

    private function validateConstraints(string $resource, string $action, SecurityContext $context): bool
    {
        // Check time-based constraints
        if (!$this->checkTimeConstraints($context)) {
            return false;
        }

        // Verify location constraints
        if (!$this->checkLocationConstraints($context)) {
            return false;
        }

        // Check resource state
        if (!$this->checkResourceState($resource)) {
            return false;
        }

        return true;
    }

    private function verifySecurityContext(SecurityContext $context): bool
    {
        // Verify authentication
        if (!$context->isAuthenticated()) {
            return false;
        }

        // Check session validity
        if (!$context->hasValidSession()) {
            return false;
        }

        // Verify security level
        if (!$this->hasRequiredSecurityLevel($context)) {
            return false;
        }

        return true;
    }

    private function grantPermission(string $role, string $permission, SecurityContext $context): void
    {
        DB::table('role_permissions')->insert([
            'role' => $role,
            'permission' => $permission,
            'granted_by' => $context->getUserId(),
            'granted_at' => now()
        ]);
    }

    private function revokePermission(string $role, string $permission, SecurityContext $context): void
    {
        DB::table('role_permissions')
            ->where('role', $role)
            ->where('permission', $permission)
            ->update([
                'revoked_by' => $context->getUserId(),
                'revoked_at' => now()
            ]);
    }

    private function updateRoleCache(string $role): void
    {
        $permissions = $this->roleManager->getRolePermissions($role);
        Cache::put("role_permissions:$role", $permissions, $this->config['cache_ttl']);
    }

    private function invalidateRoleCache(string $role): void
    {
        Cache::forget("role_permissions:$role");
    }

    private function handleAccessFailure(\Exception $e, string $resource, string $action, SecurityContext $context): void
    {
        $this->audit->logAccessFailure($resource, $action, $context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function handleGrantFailure(\Exception $e, string $role, array $permissions, SecurityContext $context): void
    {
        $this->audit->logGrantFailure($role, $permissions, $context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function handleRevokeFailure(\Exception $e, string $role, array $permissions, SecurityContext $context): void
    {
        $this->audit->logRevokeFailure($role, $permissions, $context, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
