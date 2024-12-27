<?php

namespace App\Core\Auth;

class AuthenticationService implements AuthenticationInterface
{
    private UserRepository $users;
    private TokenManager $tokens;
    private HashManager $hash;
    private SecurityService $security;
    private EventManager $events;

    public function __construct(
        UserRepository $users,
        TokenManager $tokens,
        HashManager $hash,
        SecurityService $security,
        EventManager $events
    ) {
        $this->users = $users;
        $this->tokens = $tokens;
        $this->hash = $hash;
        $this->security = $security;
        $this->events = $events;
    }

    public function attempt(array $credentials): bool
    {
        try {
            $user = $this->validateCredentials($credentials);
            
            if (!$user) {
                $this->events->dispatch(new AuthenticationFailed($credentials));
                return false;
            }

            $this->login($user);
            return true;

        } catch (\Exception $e) {
            $this->security->handleAuthError($e);
            return false;
        }
    }

    public function login(User $user): void
    {
        $token = $this->tokens->create($user);
        
        $this->security->setAuthToken($token);
        
        $this->events->dispatch(new UserLoggedIn($user));
    }

    public function logout(): void
    {
        if ($user = $this->security->getAuthUser()) {
            $this->tokens->revoke($this->security->getAuthToken());
            $this->security->clearAuth();
            
            $this->events->dispatch(new UserLoggedOut($user));
        }
    }

    protected function validateCredentials(array $credentials): ?User
    {
        if (!isset($credentials['email'], $credentials['password'])) {
            return null;
        }

        $user = $this->users->findByEmail($credentials['email']);

        if (!$user || !$this->hash->verify($credentials['password'], $user->password)) {
            return null;
        }

        return $user;
    }
}

class AuthorizationService implements AuthorizationInterface
{
    private PermissionRegistry $permissions;
    private SecurityService $security;
    private CacheService $cache;
    private EventManager $events;

    public function authorize(string $ability, $resource = null): bool
    {
        $user = $this->security->getAuthUser();

        if (!$user) {
            return false;
        }

        return $this->cache->remember(
            $this->getPermissionCacheKey($user, $ability, $resource),
            fn() => $this->checkPermission($user, $ability, $resource)
        );
    }

    protected function checkPermission(User $user, string $ability, $resource = null): bool
    {
        $permission = $this->permissions->get($ability);

        if (!$permission) {
            return false;
        }

        if ($permission->requiresResource() && !$resource) {
            return false;
        }

        $result = $permission->authorize($user, $resource);

        $this->events->dispatch(
            new PermissionChecked($user, $ability, $resource, $result)
        );

        return $result;
    }

    protected function getPermissionCacheKey(User $user, string $ability, $resource = null): string
    {
        return sprintf(
            'permission:%s:%s:%s',
            $user->id,
            $ability,
            $resource ? md5(serialize($resource)) : 'null'
        );
    }
}

class RoleManager implements RoleManagerInterface
{
    private RoleRepository $roles;
    private PermissionRegistry $permissions;
    private SecurityService $security;
    private EventManager $events;

    public function assignRole(User $user, Role $role): void
    {
        DB::transaction(function() use ($user, $role) {
            $this->security->validateRoleAssignment($user, $role);
            
            $user->roles()->attach($role);
            
            $this->events->dispatch(new RoleAssigned($user, $role));
        });
    }

    public function removeRole(User $user, Role $role): void
    {
        DB::transaction(function() use ($user, $role) {
            $user->roles()->detach($role);
            
            $this->events->dispatch(new RoleRemoved($user, $role));
        });
    }

    public function syncPermissions(Role $role, array $permissions): void
    {
        DB::transaction(function() use ($role, $permissions) {
            $role->permissions()->sync($permissions);
            
            $this->events->dispatch(new RolePermissionsUpdated($role));
        });
    }
}