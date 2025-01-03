<?php

namespace App\Core\Security;

class AuthorizationService implements AuthorizationInterface 
{
    private PermissionManager $permissions;
    private RoleManager $roles;
    private ResourceManager $resources;
    private AuditLogger $auditLogger;
    private CacheManager $cache;
    private SecurityConfig $config;

    public function __construct(
        PermissionManager $permissions,
        RoleManager $roles,
        ResourceManager $resources,
        AuditLogger $auditLogger,
        CacheManager $cache,
        SecurityConfig $config
    ) {
        $this->permissions = $permissions;
        $this->roles = $roles;
        $this->resources = $resources;
        $this->auditLogger = $auditLogger;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function authorize(AuthorizationRequest $request): AuthorizationResult 
    {
        $cacheKey = $this->buildCacheKey($request);
        
        return $this->cache->remember($cacheKey, function() use ($request) {
            return $this->processAuthorization($request);
        }, $this->config->getAuthorizationCacheTtl());
    }

    public function validateAccess(User $user, Resource $resource, string $permission): bool 
    {
        try {
            $request = new AuthorizationRequest($user, $resource, $permission);
            $result = $this->authorize($request);
            
            $this->auditLogger->logAccessCheck($user, $resource, $permission, $result->isAuthorized());
            
            return $result->isAuthorized();
            
        } catch (\Exception $e) {
            $this->handleAuthorizationFailure($e, $user, $resource, $permission);
            return false;
        }
    }

    public function addRole(Role $role): void 
    {
        try {
            DB::beginTransaction();
            
            $this->validateRole($role);
            $this->roles->createRole($role);
            $this->clearRoleCache();
            
            $this->auditLogger->logRoleCreated($role);
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RoleManagementException('Failed to create role', 0, $e);
        }
    }

    public function updateRole(Role $role): void 
    {
        try {
            DB::beginTransaction();
            
            $this->validateRole($role);
            $this->roles->updateRole($role);
            $this->clearRoleCache();
            
            $this->auditLogger->logRoleUpdated($role);
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RoleManagementException('Failed to update role', 0, $e);
        }
    }

    public function assignRole(User $user, Role $role): void 
    {
        try {
            DB::beginTransaction();
            
            $this->validateRoleAssignment($user, $role);
            $this->roles->assignRole($user, $role);
            $this->clearUserRoleCache($user);
            
            $this->auditLogger->logRoleAssigned($user, $role);
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new RoleManagementException('Failed to assign role', 0, $e);
        }
    }

    public function addPermission(Permission $permission): void 
    {
        try {
            DB::beginTransaction();
            
            $this->validatePermission($permission);
            $this->permissions->createPermission($permission);
            $this->clearPermissionCache();
            
            $this->auditLogger->logPermissionCreated($permission);
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new PermissionManagementException('Failed to create permission', 0, $e);
        }
    }

    public function grantPermission(Role $role, Permission $permission): void 
    {
        try {
            DB::beginTransaction();
            
            $this->validatePermissionGrant($role, $permission);
            $this->permissions->grantPermission($role, $permission);
            $this->clearRolePermissionCache($role);
            
            $this->auditLogger->logPermissionGranted($role, $permission);
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new PermissionManagementException('Failed to grant permission', 0, $e);
        }
    }

    private function processAuthorization(AuthorizationRequest $request): AuthorizationResult 
    {
        $this->validateRequest($request);
        
        $user = $request->getUser();
        $resource = $request->getResource();
        $permission = $request->getPermission();
        
        $roles = $this->roles->getUserRoles($user);
        $permissions = $this->permissions->getRolePermissions($roles);
        
        $authorized = $this->checkPermission($permissions, $resource, $permission);
        
        $this->auditLogger->logAuthorizationCheck($user, $resource, $permission, $authorized);
        
        return new AuthorizationResult($authorized);
    }

    private function checkPermission(array $permissions, Resource $resource, string $permission): bool 
    {
        foreach ($permissions as $perm) {
            if ($perm->matches($resource, $permission)) {
                return true;
            }
        }
        return false;
    }

    private function validateRequest(AuthorizationRequest $request): void 
    {
        if (!$request->isValid()) {
            throw new InvalidRequestException('Invalid authorization request');
        }

        if (!$this->resources->exists($request->getResource())) {
            throw new ResourceNotFoundException('Resource not found');
        }
    }

    private function validateRole(Role $role): void 
    {
        if (!$role->isValid()) {
            throw new InvalidRoleException('Invalid role configuration');
        }
    }

    private function validateRoleAssignment(User $user, Role $role): void 
    {
        if ($this->roles->hasRole($user, $role)) {
            throw new DuplicateRoleException('User already has this role');
        }
    }

    private function validatePermission(Permission $permission): void 
    {
        if (!$permission->isValid()) {
            throw new InvalidPermissionException('Invalid permission configuration');
        }
    }

    private function validatePermissionGrant(Role $role, Permission $permission): void 
    {
        if ($this->permissions->hasPermission($role, $permission)) {
            throw new DuplicatePermissionException('Role already has this permission');
        }
    }

    private function handleAuthorizationFailure(\Exception $e, User $user, Resource $resource, string $permission): void 
    {
        $this->auditLogger->logAuthorizationFailure($user, $resource, $permission, $e);
    }

    private function buildCacheKey(AuthorizationRequest $request): string 
    {
        return sprintf(
            'auth:%s:%s:%s',
            $request->getUser()->getId(),
            $request->getResource()->getId(),
            $request->getPermission()
        );
    }

    private function clearRoleCache(): void 
    {
        $this->cache->tags(['roles'])->flush();
    }

    private function clearUserRoleCache(User $user): void 