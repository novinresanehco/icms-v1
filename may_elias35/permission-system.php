```php
namespace App\Core\Access;

class PermissionManager implements PermissionInterface
{
    private SecurityManager $security;
    private RoleRegistry $roles;
    private CacheManager $cache;
    private AuditLogger $audit;

    public function checkPermission(User $user, string $permission): bool
    {
        return $this->security->executeProtected(function() use ($user, $permission) {
            $cacheKey = "permissions.{$user->id}.{$permission}";
            
            return $this->cache->remember($cacheKey, function() use ($user, $permission) {
                $hasPermission = $this->verifyPermission($user, $permission);
                $this->audit->logPermissionCheck($user, $permission, $hasPermission);
                return $hasPermission;
            });
        });
    }

    private function verifyPermission(User $user, string $permission): bool
    {
        $role = $this->roles->getRole($user->role_id);
        
        if (!$role) {
            throw new RoleNotFoundException();
        }

        $this->validateRole($role);
        return $role->hasPermission($permission);
    }

    private function validateRole(Role $role): void
    {
        if (!$role->isActive() || $role->isExpired()) {
            throw new InvalidRoleException();
        }
    }
}

class RoleRegistry
{
    private array $roles = [];
    private SecurityManager $security;
    private CacheManager $cache;

    public function getRole(int $roleId): ?Role
    {
        return $this->cache->remember("role.$roleId", function() use ($roleId) {
            return $this->loadRole($roleId);
        });
    }

    private function loadRole(int $roleId): ?Role
    {
        $roleData = Role::find($roleId);
        
        if (!$roleData) {
            return null;
        }

        $permissions = $this->security->decryptPermissions($roleData->permissions);
        return new Role($roleData, $permissions);
    }

    public function assignRole(User $user, Role $role): void
    {
        $this->security->executeProtected(function() use ($user, $role) {
            $user->role_id = $role->id;
            $user->save();
            
            $this->cache->invalidate([
                "role.{$role->id}",
                "permissions.{$user->id}.*"
            ]);
        });
    }
}

class Role implements RoleInterface
{
    private array $data;
    private array $permissions;
    private ValidationService $validator;

    public function hasPermission(string $permission): bool
    {
        if (isset($this->permissions[$permission])) {
            return $this->permissions[$permission]['active'] ?? false;
        }
        
        return false;
    }

    public function isActive(): bool
    {
        return $this->data['status'] === 'active';
    }

    public function isExpired(): bool
    {
        return $this->data['expires_at'] && 
               now()->isAfter($this->data['expires_at']);
    }

    public function addPermission(string $permission): void
    {
        $this->validator->validatePermission($permission);
        
        $this->permissions[$permission] = [
            'active' => true,
            'granted_at' => now()
        ];
    }

    public function removePermission(string $permission): void
    {
        if (isset($this->permissions[$permission])) {
            $this->permissions[$permission]['active'] = false;
            $this->permissions[$permission]['revoked_at'] = now();
        }
    }
}

class AccessControl implements AccessInterface
{
    private PermissionManager $permissions;
    private SecurityManager $security;
    private AuditLogger $audit;

    public function validateAccess(User $user, string $resource, string $action): bool
    {
        $context = ['user' => $user, 'resource' => $resource, 'action' => $action];
        
        return $this->security->executeProtected(function() use ($context) {
            $permission = "{$context['resource']}.{$context['action']}";
            
            $hasAccess = $this->permissions->checkPermission(
                $context['user'], 
                $permission
            );

            $this->audit->logAccessAttempt($context, $hasAccess);
            
            if (!$hasAccess) {
                throw new AccessDeniedException($context);
            }

            return true;
        });
    }
}
```
