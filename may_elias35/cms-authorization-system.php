<?php

namespace App\Core\Authorization;

use App\Core\Exceptions\AuthorizationException;
use App\Models\User;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Exception;

class AuthorizationManager
{
    protected PermissionRegistry $permissionRegistry;
    protected RoleManager $roleManager;
    protected PolicyManager $policyManager;
    protected AuthorizationCache $cache;
    protected AuthorizationMetrics $metrics;

    public function __construct(
        PermissionRegistry $permissionRegistry,
        RoleManager $roleManager,
        PolicyManager $policyManager,
        AuthorizationCache $cache,
        AuthorizationMetrics $metrics
    ) {
        $this->permissionRegistry = $permissionRegistry;
        $this->roleManager = $roleManager;
        $this->policyManager = $policyManager;
        $this->cache = $cache;
        $this->metrics = $metrics;
    }

    public function authorize(User $user, string $permission, $resource = null): bool
    {
        $startTime = microtime(true);

        try {
            // Check if user is super admin
            if ($this->isSuperAdmin($user)) {
                $this->recordAuthorization($user, $permission, true, $startTime);
                return true;
            }

            // Check cached permissions first
            if ($this->cache->hasPermission($user, $permission)) {
                $this->recordAuthorization($user, $permission, true, $startTime);
                return true;
            }

            // Check direct permissions
            if ($this->hasDirectPermission($user, $permission)) {
                $this->cache->cachePermission($user, $permission);
                $this->recordAuthorization($user, $permission, true, $startTime);
                return true;
            }

            // Check role-based permissions
            if ($this->hasRolePermission($user, $permission)) {
                $this->cache->cachePermission($user, $permission);
                $this->recordAuthorization($user, $permission, true, $startTime);
                return true;
            }

            // Check policy-based permissions
            if ($resource && $this->policyManager->authorize($user, $permission, $resource)) {
                $this->recordAuthorization($user, $permission, true, $startTime);
                return true;
            }

            $this->recordAuthorization($user, $permission, false, $startTime);
            return false;

        } catch (Exception $e) {
            $this->handleAuthorizationError($e, $user, $permission);
            throw new AuthorizationException(
                "Authorization check failed: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    public function authorizeOrFail(User $user, string $permission, $resource = null): void
    {
        if (!$this->authorize($user, $permission, $resource)) {
            throw new AuthorizationException(
                "User does not have required permission: {$permission}"
            );
        }
    }

    public function grantPermission(User $user, string $permission): void
    {
        try {
            $permissionModel = $this->permissionRegistry->getPermission($permission);
            $user->permissions()->attach($permissionModel->id);
            $this->cache->clearUserPermissions($user);
        } catch (Exception $e) {
            throw new AuthorizationException(
                "Failed to grant permission: {$e->getMessage()}"
            );
        }
    }

    public function revokePermission(User $user, string $permission): void
    {
        try {
            $permissionModel = $this->permissionRegistry->getPermission($permission);
            $user->permissions()->detach($permissionModel->id);
            $this->cache->clearUserPermissions($user);
        } catch (Exception $e) {
            throw new AuthorizationException(
                "Failed to revoke permission: {$e->getMessage()}"
            );
        }
    }

    protected function isSuperAdmin(User $user): bool
    {
        return $this->roleManager->userHasRole($user, 'super-admin');
    }

    protected function hasDirectPermission(User $user, string $permission): bool
    {
        return $user->permissions()
            ->where('name', $permission)
            ->exists();
    }

    protected function hasRolePermission(User $user, string $permission): bool
    {
        return $user->roles()
            ->whereHas('permissions', function ($query) use ($permission) {
                $query->where('name', $permission);
            })
            ->exists();
    }

    protected function recordAuthorization(
        User $user,
        string $permission,
        bool $granted,
        float $startTime
    ): void {
        $this->metrics->recordAuthorization([
            'user_id' => $user->id,
            'permission' => $permission,
            'granted' => $granted,
            'duration' => microtime(true) - $startTime,
            'timestamp' => microtime(true)
        ]);
    }

    protected function handleAuthorizationError(
        Exception $e,
        User $user,
        string $permission
    ): void {
        Log::error('Authorization error', [
            'user_id' => $user->id,
            'permission' => $permission,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

class PermissionRegistry
{
    protected Collection $permissions;
    protected array $config;

    public function __construct()
    {
        $this->permissions = new Collection();
        $this->config = config('authorization.permissions', []);
        $this->loadPermissions();
    }

    public function registerPermission(string $name, array $attributes = []): Permission
    {
        $permission = Permission::firstOrCreate([
            'name' => $name
        ], array_merge([
            'description' => '',
            'category' => 'general'
        ], $attributes));

        $this->permissions->put($name, $permission);

        return $permission;
    }

    public function getPermission(string $name): Permission
    {
        if (!$this->permissions->has($name)) {
            throw new AuthorizationException("Permission not found: {$name}");
        }

        return $this->permissions->get($name);
    }

    protected function loadPermissions(): void
    {
        foreach ($this->config as $name => $attributes) {
            $this->registerPermission($name, $attributes);
        }
    }
}

class RoleManager
{
    protected Collection $roles;
    protected AuthorizationCache $cache;

    public function __construct(AuthorizationCache $cache)
    {
        $this->roles = new Collection();
        $this->cache = $cache;
    }

    public function createRole(string $name, array $permissions = []): Role
    {
        $role = Role::create(['name' => $name]);
        
        if (!empty($permissions)) {
            $role->permissions()->attach($permissions);
        }

        $this->roles->put($name, $role);
        $this->cache->clearRoleCache($role);

        return $role;
    }

    public function assignRole(User $user, string $role): void
    {
        $roleModel = $this->getRole($role);
        $user->roles()->attach($roleModel->id);
        $this->cache->clearUserRoles($user);
    }

    public function removeRole(User $user, string $role): void
    {
        $roleModel = $this->getRole($role);
        $user->roles()->detach($roleModel->id);
        $this->cache->clearUserRoles($user);
    }

    public function userHasRole(User $user, string $role): bool
    {
        return $user->roles()
            ->where('name', $role)
            ->exists();
    }

    protected function getRole(string $name): Role
    {
        $role = Role::where('name', $name)->first();
        
        if (!$role) {
            throw new AuthorizationException("Role not found: {$name}");
        }

        return $role;
    }
}

class PolicyManager
{
    protected Collection $policies;

    public function __construct()
    {
        $this->policies = new Collection();
        $this->registerPolicies();
    }

    public function registerPolicy(string $resource, string $policy): void
    {
        $this->policies->put($resource, $policy);
    }

    public function authorize(User $user, string $permission, $resource): bool
    {
        $policy = $this->getPolicy($resource);

        if (!$policy) {
            return false;
        }

        return $policy->authorize($user, $permission, $resource);
    }

    protected function getPolicy($resource): ?Policy
    {
        $resourceType = is_object($resource) ? get_class($resource) : gettype($resource);
        return $this->policies->get($resourceType);
    }

    protected function registerPolicies(): void
    {
        foreach (config('authorization.policies', []) as $resource => $policy) {
            $this->registerPolicy($resource, $policy);
        }
    }
}

class AuthorizationCache
{
    protected array $config;

    public function __construct()
    {
        $this->config = config('authorization.cache', [
            'ttl' => 3600,
            'prefix' => 'auth'
        ]);
    }

    public function hasPermission(User $user, string $permission): bool
    {
        $key = $this->getPermissionKey($user, $permission);
        return Cache::has($key);
    }

    public function cachePermission(User $user, string $permission): void
    {
        $key = $this->getPermissionKey($user, $permission);
        Cache::put($key, true, $this->config['ttl']);
    }

    public function clearUserPermissions(User $user): void
    {
        $pattern = $this->getPermissionKey($user, '*');
        $this->clearCachePattern($pattern);
    }

    public function clearUserRoles(User $user): void
    {
        $pattern = $this->getRoleKey($user, '*');
        $this->clearCachePattern($pattern);
    }

    public function clearRoleCache(Role $role): void
    {
        $pattern = "role:{$role->id}:*";
        $this->clearCachePattern($pattern);
    }

    protected function getPermissionKey(User $user, string $permission): string
    {
        return "{$this->config['prefix']}:user:{$user->id}:permission:{$permission}";
    }

    protected function getRoleKey(User $user, string $role): string
    {
        return "{$this->config['prefix']}:user:{$user->id}:role:{$role}";
    }

    protected function clearCachePattern(string $pattern): void
    {
        // Implementation depends on cache driver
        Cache::tags([$pattern])->flush();
    }
}

class AuthorizationMetrics
{
    protected Collection $metrics;

    public function __construct()
    {
        $this->metrics = new Collection();
    }

    public function recordAuthorization(array $data): void
    {
        $this->metrics->push($data);
        
        if ($this->metrics->count() > 1000) {
            $this->flush();
        }
    }

    public function getMetrics(): Collection
    {
        return $this->metrics;
    }

    protected function flush(): void
    {
        // Implement metric storage logic here
        $this->metrics = new Collection();
    }
}

abstract class Policy
{
    abstract public function authorize(User $user, string $permission, $resource): bool;
}
