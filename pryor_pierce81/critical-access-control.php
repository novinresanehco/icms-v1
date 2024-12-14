<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{DB, Cache};
use App\Core\Interfaces\{
    AccessControlInterface,
    SecurityManagerInterface,
    AuditLoggerInterface
};

class AccessControlSystem implements AccessControlInterface
{
    private SecurityManagerInterface $security;
    private AuditLoggerInterface $audit;
    private PermissionRegistry $permissions;
    private RoleHierarchy $roles;
    private AccessCache $cache;

    public function __construct(
        SecurityManagerInterface $security,
        AuditLoggerInterface $audit,
        PermissionRegistry $permissions,
        RoleHierarchy $roles,
        AccessCache $cache
    ) {
        $this->security = $security;
        $this->audit = $audit;
        $this->permissions = $permissions;
        $this->roles = $roles;
        $this->cache = $cache;
    }

    public function validateAccess(AccessRequest $request): bool
    {
        return $this->security->executeCriticalOperation(
            new ValidateAccessOperation($request, $this)
        );
    }

    public function checkPermission(User $user, string $permission): bool
    {
        $cacheKey = "permission:{$user->id}:{$permission}";

        return $this->cache->remember($cacheKey, function() use ($user, $permission) {
            $roles = $this->roles->getUserRoles($user);
            
            foreach ($roles as $role) {
                if ($this->roleHasPermission($role, $permission)) {
                    return true;
                }
            }
            
            return false;
        });
    }

    private function roleHasPermission(Role $role, string $permission): bool
    {
        return $this->permissions->checkPermission($role, $permission);
    }
}

class PermissionRegistry
{
    private array $permissions = [];
    private ValidationService $validator;

    public function registerPermission(Permission $permission): void
    {
        $this->validator->validatePermission($permission);
        $this->permissions[$permission->getName()] = $permission;
    }

    public function checkPermission(Role $role, string $permission): bool
    {
        if (!isset($this->permissions[$permission])) {
            throw new PermissionException("Unknown permission: $permission");
        }

        return $this->permissions[$permission]->isGranted($role);
    }
}

class RoleHierarchy
{
    private array $hierarchy = [];
    private array $cache = [];

    public function addRole(Role $role, ?Role $parent = null): void
    {
        $this->hierarchy[$role->getName()] = [
            'role' => $role,
            'parent' => $parent ? $parent->getName() : null
        ];
        
        $this->clearCache();
    }

    public function getUserRoles(User $user): array
    {
        $cacheKey = "user_roles:{$user->id}";

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $roles = $this->calculateUserRoles($user);
        $this->cache[$cacheKey] = $roles;

        return $roles;
    }

    private function calculateUserRoles(User $user): array
    {
        $roles = [$user->getRole()];
        $currentRole = $user->getRole();

        while ($parent = $this->getParentRole($currentRole)) {
            $roles[] = $parent;
            $currentRole = $parent;
        }

        return $roles;
    }

    private function getParentRole(Role $role): ?Role
    {
        $parentName = $this->hierarchy[$role->getName()]['parent'] ?? null;
        return $parentName ? $this->hierarchy[$parentName]['role'] : null;
    }

    private function clearCache(): void
    {
        $this->cache = [];
    }
}

class AccessCache
{
    private CacheManager $cache;
    private int $ttl;

    public function remember(string $key, callable $callback)
    {
        return $this->cache->remember($key, $this->ttl, function() use ($callback) {
            return $callback();
        });
    }

    public function invalidate(string $key): void
    {
        $this->cache->forget($key);
    }
}

class Permission
{
    private string $name;
    private string $description;
    private array $constraints;

    public function __construct(string $name, string $description, array $constraints = [])
    {
        $this->name = $name;
        $this->description = $description;
        $this->constraints = $constraints;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isGranted(Role $role): bool
    {
        foreach ($this->constraints as $constraint) {
            if (!$constraint->isSatisfiedBy($role)) {
                return false;
            }
        }
        return true;
    }
}

class Role
{
    private string $name;
    private array $permissions;
    private array $attributes;

    public function __construct(string $name, array $permissions = [], array $attributes = [])
    {
        $this->name = $name;
        $this->permissions = $permissions;
        $this->attributes = $attributes;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions);
    }

    public function getAttribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }
}

class AccessRequest
{
    private User $user;
    private string $resource;
    private string $action;
    private array $context;

    public function __construct(User $user, string $resource, string $action, array $context = [])
    {
        $this->user = $user;
        $this->resource = $resource;
        $this->action = $action;
        $this->context = $context;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getResource(): string
    {
        return $this->resource;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getContext(): array
    {
        return $this->context;
    }
}

class ValidateAccessOperation implements CriticalOperation
{
    private AccessRequest $request;
    private AccessControlSystem $accessControl;

    public function __construct(AccessRequest $request, AccessControlSystem $accessControl)
    {
        $this->request = $request;
        $this->accessControl = $accessControl;
    }

    public function execute(): bool
    {
        return $this->accessControl->checkPermission(
            $this->request->getUser(),
            $this->getRequiredPermission()
        );
    }

    private function getRequiredPermission(): string
    {
        return sprintf(
            '%s:%s',
            $this->request->getResource(),
            $this->request->getAction()
        );
    }
}
