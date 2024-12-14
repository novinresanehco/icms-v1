<?php

namespace App\Core\Authorization;

class AuthorizationManager implements AuthorizationInterface
{
    private RoleManager $roleManager;
    private PermissionManager $permissions;
    private SecurityManager $security;
    private ValidationService $validator;
    private AuditLogger $logger;
    private CacheManager $cache;

    public function __construct(
        RoleManager $roleManager,
        PermissionManager $permissions,
        SecurityManager $security,
        ValidationService $validator,
        AuditLogger $logger,
        CacheManager $cache
    ) {
        $this->roleManager = $roleManager;
        $this->permissions = $permissions;
        $this->security = $security;
        $this->validator = $validator;
        $this->logger = $logger;
        $this->cache = $cache;
    }

    public function authorize(User $user, string $resource, string $action): bool
    {
        $operationId = uniqid('auth_', true);
        
        try {
            $this->validateAuthorizationRequest($user, $resource, $action);
            $this->security->validateSecurityContext();
            
            if ($this->hasExplicitPermission($user, $resource, $action)) {
                $this->logAuthorization($operationId, $user, $resource, $action, true);
                return true;
            }

            $roles = $this->getRoles($user);
            $hasPermission = $this->checkRolePermissions($roles, $resource, $action);
            
            $this->logAuthorization($operationId, $user, $resource, $action, $hasPermission);
            return $hasPermission;
            
        } catch (\Exception $e) {
            $this->handleAuthorizationFailure($operationId, $user, $resource, $action, $e);
            throw new AuthorizationException('Authorization failed', 0, $e);
        }
    }

    public function validatePermissions(array $requiredPermissions): void
    {
        foreach ($requiredPermissions as $permission) {
            if (!$this->permissions->isValidPermission($permission)) {
                throw new InvalidPermissionException("Invalid permission: {$permission}");
            }
        }
    }

    public function assignRole(User $user, Role $role): void
    {
        DB::beginTransaction();
        
        try {
            $this->validator->validateRoleAssignment($user, $role);
            $this->security->validateRoleChange($user, $role);
            
            $this->roleManager->assignRole($user, $role);
            $this->invalidateUserPermissions($user);
            
            $this->logger->logRoleAssignment($user, $role);
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logger->logRoleAssignmentFailure($user, $role, $e);
            throw new RoleAssignmentException('Role assignment failed', 0, $e);
        }
    }

    private function validateAuthorizationRequest(User $user, string $resource, string $action): void
    {
        if (!$user->isActive()) {
            throw new InactiveUserException('User is not active');
        }

        if (!$this->validator->validateResource($resource)) {
            throw new InvalidResourceException('Invalid resource');
        }

        if (!$this->validator->validateAction($action)) {
            throw new InvalidActionException('Invalid action');
        }
    }

    private function hasExplicitPermission(User $user, string $resource, string $action): bool
    {
        $cacheKey = "user_permission:{$user->getId()}:{$resource}:{$action}";
        
        return $this->cache->remember($cacheKey, 3600, function() use ($user, $resource, $action) {
            return $this->permissions->hasDirectPermission($user, $resource, $action);
        });
    }

    private function getRoles(User $user): array
    {
        $cacheKey = "user_roles:{$user->getId()}";
        
        return $this->cache->remember($cacheKey, 3600, function() use ($user) {
            return $this->roleManager->getUserRoles($user);
        });
    }

    private function checkRolePermissions(array $roles, string $resource, string $action): bool
    {
        foreach ($roles as $role) {
            if ($this->roleHasPermission($role, $resource, $action)) {
                return true;
            }
        }
        return false;
    }

    private function roleHasPermission(Role $role, string $resource, string $action): bool
    {
        $cacheKey = "role_permission:{$role->getId()}:{$resource}:{$action}";
        
        return $this->cache->remember($cacheKey, 3600, function() use ($role, $resource, $action) {
            return $this->permissions->checkRolePermission($role, $resource, $action);
        });
    }

    private function invalidateUserPermissions(User $user): void
    {
        $this->cache->deletePattern("user_permission:{$user->getId()}:*");
        $this->cache->delete("user_roles:{$user->getId()}");
    }

    private function logAuthorization(
        string $operationId,
        User $user,
        string $resource,
        string $action,
        bool $granted
    ): void {
        $this->logger->logAuthorization([
            'operation_id' => $operationId,
            'user_id' => $user->getId(),
            'resource' => $resource,
            'action' => $action,
            'granted' => $granted,
            'timestamp' => now(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);
    }

    private function handleAuthorizationFailure(
        string $operationId,
        User $user,
        string $resource,
        string $action,
        \Exception $e
    ): void {
        $this->logger->logAuthorizationFailure([
            'operation_id' => $operationId,
            'user_id' => $user->getId(),
            'resource' => $resource,
            'action' => $action,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => now()
        ]);

        if ($e instanceof SecurityException) {
            $this->security->handleSecurityIncident($operationId, $e);
        }
    }
}
