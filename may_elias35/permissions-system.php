// File: app/Core/Permissions/Manager/PermissionManager.php
<?php

namespace App\Core\Permissions\Manager;

class PermissionManager
{
    protected RoleRepository $roleRepository;
    protected PermissionRepository $permissionRepository;
    protected Cache $cache;
    protected AccessValidator $validator;

    public function check(User $user, string $permission, $resource = null): bool
    {
        $cacheKey = $this->getCacheKey($user->id, $permission, $resource);

        return $this->cache->remember($cacheKey, function() use ($user, $permission, $resource) {
            // Check direct permissions
            if ($this->hasDirectPermission($user, $permission)) {
                return true;
            }

            // Check role-based permissions
            if ($this->hasRolePermission($user, $permission)) {
                return true;
            }

            // Check resource-specific permissions
            if ($resource && $this->hasResourcePermission($user, $permission, $resource)) {
                return true;
            }

            return false;
        });
    }

    public function grant(User $user, string $permission): void
    {
        DB::transaction(function() use ($user, $permission) {
            $this->permissionRepository->grant($user, $permission);
            $this->cache->flush($user->id);
        });
    }

    public function revoke(User $user, string $permission): void
    {
        DB::transaction(function() use ($user, $permission) {
            $this->permissionRepository->revoke($user, $permission);
            $this->cache->flush($user->id);
        });
    }
}

// File: app/Core/Permissions/Role/RoleManager.php
<?php

namespace App\Core\Permissions\Role;

class RoleManager
{
    protected RoleRepository $repository;
    protected PermissionManager $permissionManager;
    protected RoleValidator $validator;

    public function createRole(string $name, array $permissions = []): Role
    {
        $this->validator->validateName($name);

        return DB::transaction(function() use ($name, $permissions) {
            $role = $this->repository->create(['name' => $name]);
            
            if (!empty($permissions)) {
                $this->permissionManager->assignToRole($role, $permissions);
            }
            
            return $role;
        });
    }

    public function assignRole(User $user, Role $role): void
    {
        if ($user->hasRole($role)) {
            throw new RoleException("User already has this role");
        }

        DB::transaction(function() use ($user, $role) {
            $user->roles()->attach($role->id);
            $this->permissionManager->cache->flush($user->id);
        });
    }

    public function removeRole(User $user, Role $role): void
    {
        DB::transaction(function() use ($user, $role) {
            $user->roles()->detach($role->id);
            $this->permissionManager->cache->flush($user->id);
        });
    }
}

// File: app/Core/Permissions/Policy/PolicyManager.php
<?php

namespace App\Core\Permissions\Policy;

class PolicyManager
{
    protected array $policies = [];
    protected PolicyValidator $validator;
    protected PolicyCache $cache;

    public function registerPolicy(string $resource, Policy $policy): void
    {
        $this->validator->validate($policy);
        $this->policies[$resource] = $policy;
    }

    public function authorize(User $user, string $action, $resource): bool
    {
        $policy = $this->getPolicy($resource);
        
        if (!$policy) {
            throw new PolicyException("No policy registered for resource");
        }

        if (method_exists($policy, 'before')) {
            $result = $policy->before($user, $action, $resource);
            if (!is_null($result)) {
                return $result;
            }
        }

        if (!method_exists($policy, $action)) {
            throw new PolicyException("Action not found in policy");
        }

        return $policy->$action($user, $resource);
    }

    protected function getPolicy($resource): ?Policy
    {
        $type = is_object($resource) ? get_class($resource) : $resource;
        return $this->policies[$type] ?? null;
    }
}

// File: app/Core/Permissions/Validation/AccessValidator.php
<?php

namespace App\Core\Permissions\Validation;

class AccessValidator
{
    protected PermissionConfig $config;
    protected ValidationLog $log;

    public function validateAccess(User $user, string $permission, $resource = null): bool
    {
        if ($user->isAdmin()) {
            $this->log->info('Admin access granted', [
                'user' => $user->id,
                'permission' => $permission
            ]);
            return true;
        }

        $result = $user->can($permission);
        
        $this->log->info($result ? 'Access granted' : 'Access denied', [
            'user' => $user->id,
            'permission' => $permission,
            'resource' => $resource ? get_class($resource) : null
        ]);

        return $result;
    }
}
