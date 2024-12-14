namespace App\Core\Permission;

class PermissionManager implements PermissionInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private ValidationService $validator;
    private AuditLogger $logger;
    private array $config;

    public function checkPermission(User $user, string $permission, ?Resource $resource = null): bool 
    {
        return $this->security->executeCriticalOperation(
            new CheckPermissionOperation($user, $permission),
            function() use ($user, $permission, $resource) {
                // Check cache first
                $cacheKey = $this->getPermissionCacheKey($user, $permission, $resource);
                
                return $this->cache->remember($cacheKey, function() use ($user, $permission, $resource) {
                    // Validate inputs
                    $this->validatePermissionCheck($user, $permission, $resource);
                    
                    // Check super admin
                    if ($this->isSuperAdmin($user)) {
                        return true;
                    }
                    
                    // Get user roles
                    $roles = $this->getUserRoles($user);
                    
                    // Check role permissions
                    foreach ($roles as $role) {
                        if ($this->roleHasPermission($role, $permission)) {
                            // Check resource-specific permissions
                            if ($resource && !$this->checkResourcePermission($role, $permission, $resource)) {
                                continue;
                            }
                            return true;
                        }
                    }
                    
                    return false;
                });
            }
        );
    }

    public function grantPermission(Role $role, string $permission, array $constraints = []): void 
    {
        $this->security->executeCriticalOperation(
            new GrantPermissionOperation($role, $permission),
            function() use ($role, $permission, $constraints) {
                // Validate permission
                $this->validatePermission($permission);
                
                // Validate constraints
                $this->validateConstraints($constraints);
                
                // Save permission
                $this->savePermission($role, $permission, $constraints);
                
                // Clear related caches
                $this->clearPermissionCaches($role, $permission);
                
                // Log change
                $this->logPermissionGrant($role, $permission, $constraints);
            }
        );
    }

    public function revokePermission(Role $role, string $permission): void 
    {
        $this->security->executeCriticalOperation(
            new RevokePermissionOperation($role, $permission),
            function() use ($role, $permission) {
                // Validate existing permission
                $this->validateExistingPermission($role, $permission);
                
                // Remove permission
                $this->removePermission($role, $permission);
                
                // Clear related caches
                $this->clearPermissionCaches($role, $permission);
                
                // Log change
                $this->logPermissionRevoke($role, $permission);
            }
        );
    }

    protected function validatePermissionCheck(User $user, string $permission, ?Resource $resource): void 
    {
        if (!$this->validator->isValidUser($user)) {
            throw new InvalidUserException();
        }

        if (!$this->validator->isValidPermission($permission)) {
            throw new InvalidPermissionException();
        }

        if ($resource && !$this->validator->isValidResource($resource)) {
            throw new InvalidResourceException();
        }
    }

    protected function isSuperAdmin(User $user): bool 
    {
        return $user->hasRole($this->config['super_admin_role']);
    }

    protected function getUserRoles(User $user): array 
    {
        return $this->cache->remember(
            "user_roles.{$user->id}",
            fn() => $user->getRoles()
        );
    }

    protected function roleHasPermission(Role $role, string $permission): bool 
    {
        return $this->cache->remember(
            "role_permission.{$role->id}.{$permission}",
            fn() => $role->hasPermission($permission)
        );
    }

    protected function checkResourcePermission(Role $role, string $permission, Resource $resource): bool 
    {
        $constraints = $this->getPermissionConstraints($role, $permission);
        
        foreach ($constraints as $constraint) {
            if (!$this->evaluateConstraint($constraint, $resource)) {
                return false;
            }
        }
        
        return true;
    }

    protected function validatePermission(string $permission): void 
    {
        if (!isset($this->config['available_permissions'][$permission])) {
            throw new UndefinedPermissionException();
        }
    }

    protected function validateConstraints(array $constraints): void 
    {
        foreach ($constraints as $constraint) {
            if (!$this->validator->isValidConstraint($constraint)) {
                throw new InvalidConstraintException();
            }
        }
    }

    protected function savePermission(Role $role, string $permission, array $constraints): void 
    {
        $role->permissions()->create([
            'permission' => $permission,
            'constraints' => $constraints,
            'granted_by' => auth()->id(),
            'granted_at' => now()
        ]);
    }

    protected function removePermission(Role $role, string $permission): void 
    {
        $role->permissions()
            ->where('permission', $permission)
            ->delete();
    }

    protected function clearPermissionCaches(Role $role, string $permission): void 
    {
        // Clear role permission cache
        $this->cache->forget("role_permission.{$role->id}.{$permission}");
        
        // Clear user permission caches
        foreach ($role->users as $user) {
            $this->cache->forget("user_permissions.{$user->id}");
        }
    }

    protected function getPermissionCacheKey(User $user, string $permission, ?Resource $resource): string 
    {
        $key = "permission_check.{$user->id}.{$permission}";
        
        if ($resource) {
            $key .= ".{$resource->type}.{$resource->id}";
        }
        
        return $key;
    }

    protected function logPermissionGrant(Role $role, string $permission, array $constraints): void 
    {
        $this->logger->logPermissionChange([
            'type' => 'grant',
            'role_id' => $role->id,
            'permission' => $permission,
            'constraints' => $constraints,
            'user_id' => auth()->id(),
            'timestamp' => now()
        ]);
    }

    protected function logPermissionRevoke(Role $role, string $permission): void 
    {
        $this->logger->logPermissionChange([
            'type' => 'revoke',
            'role_id' => $role->id,
            'permission' => $permission,
            'user_id' => auth()->id(),
            'timestamp' => now()
        ]);
    }
}
