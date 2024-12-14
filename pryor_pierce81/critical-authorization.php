<?php

namespace App\Core\Authorization;

class AuthorizationManager implements AuthInterface 
{
    private PermissionService $permissions;
    private RoleManager $roles;
    private AuditLogger $logger;
    private AuthConfig $config;

    public function authorize(AuthRequest $request): AuthResult 
    {
        try {
            $this->validateRequest($request);
            $permissions = $this->resolvePermissions($request);
            
            if (!$this->checkPermissions($permissions)) {
                throw new UnauthorizedException();
            }

            return new AuthResult(true, $permissions);
            
        } catch (AuthException $e) {
            $this->handleAuthFailure($e, $request);
            throw $e;
        }
    }

    private function validateRequest(AuthRequest $request): void 
    {
        if (!$request->isValid()) {
            throw new InvalidAuthRequestException();
        }

        if ($this->isRateLimited($request)) {
            throw new RateLimitException();
        }
    }

    private function resolvePermissions(AuthRequest $request): array 
    {
        $role = $this->roles->getRole($request->getUser());
        return $this->permissions->resolveForRole($role);
    }

    private function isRateLimited(AuthRequest $request): bool 
    {
        return $this->config->getRateLimit($request) <= 0;
    }
}

class PermissionService implements PermissionInterface 
{
    private RoleRepository $roles;
    private PermissionCache $cache;
    private AuditLogger $logger;

    public function resolveForRole(Role $role): array 
    {
        return $this->cache->remember("permissions.{$role->getId()}", function() use ($role) {
            return $this->roles->getPermissions($role);
        });
    }

    public function validatePermission(Permission $permission, Role $role): bool 
    {
        $result = $this->roles->hasPermission($role, $permission);
        $this->logger->logPermissionCheck($permission, $role, $result);
        return $result;
    }
}

class RoleManager implements RoleInterface 
{
    private RoleRepository $repository;
    private ValidationService $validator;
    private AuditLogger $logger;

    public function assignRole(User $user, Role $role): void 
    {
        if (!$this->validator->validateRoleAssignment($user, $role)) {
            throw new InvalidRoleAssignmentException();
        }

        DB::transaction(function() use ($user, $role) {
            $this->repository->assignRole($user, $role);
            $this->logger->logRoleAssignment($user, $role);
        });
    }

    public function validateRole(Role $role): bool 
    {
        return $this->validator->validateRole($role);
    }
}
