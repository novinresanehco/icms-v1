<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{DB, Hash, Cache};
use App\Core\Security\SecurityContext;
use App\Core\Exceptions\AuthException;

class AuthManager
{
    private SecurityManager $security;
    private UserRepository $users;
    private RoleRepository $roles;
    private PermissionRepository $permissions;
    private array $config;

    public function __construct(
        SecurityManager $security,
        UserRepository $users,
        RoleRepository $roles,
        PermissionRepository $permissions,
        array $config
    ) {
        $this->security = $security;
        $this->users = $users;
        $this->roles = $roles;
        $this->permissions = $permissions;
        $this->config = $config;
    }

    public function authenticate(array $credentials, SecurityContext $context): AuthResult
    {
        return $this->security->executeCriticalOperation(function() use ($credentials) {
            $user = $this->users->findByEmail($credentials['email']);
            
            if (!$user || !Hash::check($credentials['password'], $user->password)) {
                throw new AuthException('Invalid credentials');
            }

            if (!$user->isActive()) {
                throw new AuthException('Account is deactivated');
            }

            $token = $this->createAuthToken($user);
            $this->trackLogin($user);

            return new AuthResult($user, $token);
        }, $context);
    }

    public function validateAccess(string $token, array $requiredPermissions, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(function() use ($token, $requiredPermissions) {
            $tokenData = $this->validateToken($token);
            if (!$tokenData) {
                return false;
            }

            $user = $this->users->find($tokenData->user_id);
            if (!$user || !$user->isActive()) {
                return false;
            }

            return $this->validatePermissions($user, $requiredPermissions);
        }, $context);
    }

    public function assignRole(int $userId, int $roleId, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(function() use ($userId, $roleId) {
            $user = $this->users->find($userId);
            $role = $this->roles->find($roleId);

            if (!$user || !$role) {
                throw new AuthException('User or role not found');
            }

            return $this->users->assignRole($user, $role);
        }, $context);
    }

    public function revokeRole(int $userId, int $roleId, SecurityContext $context): bool
    {
        return $this->security->executeCriticalOperation(function() use ($userId, $roleId) {
            $user = $this->users->find($userId);
            $role = $this->roles->find($roleId);

            if (!$user || !$role) {
                throw new AuthException('User or role not found');
            }

            return $this->users->revokeRole($user, $role);
        }, $context);
    }

    private function createAuthToken(User $user): string
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = now()->addMinutes($this->config['token_lifetime']);

        Cache::put(
            "auth_token:{$token}",
            [
                'user_id' => $user->id,
                'expires_at' => $expiresAt
            ],
            $expiresAt
        );

        return $token;
    }

    private function validateToken(string $token): ?object
    {
        $tokenData = Cache::get("auth_token:{$token}");
        
        if (!$tokenData || now()->isAfter($tokenData['expires_at'])) {
            return null;
        }

        return (object)$tokenData;
    }

    private function validatePermissions(User $user, array $requiredPermissions): bool
    {
        $userPermissions = Cache::remember(
            "user_permissions:{$user->id}",
            now()->addMinutes(15),
            fn() => $this->permissions->getForUser($user)
        );

        foreach ($requiredPermissions as $permission) {
            if (!in_array($permission, $userPermissions)) {
                return false;
            }
        }

        return true;
    }

    private function trackLogin(User $user): void
    {
        $this->users->updateLoginInfo($user, [
            'last_login' => now(),
            'login_ip' => request()->ip()
        ]);
    }
}

class UserRepository
{
    public function find(int $id): ?User
    {
        return User::find($id);
    }

    public function findByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }

    public function assignRole(User $user, Role $role): bool
    {
        return DB::transaction(function() use ($user, $role) {
            return DB::table('user_roles')->insert([
                'user_id' => $user->id,
                'role_id' => $role->id
            ]);
        });
    }

    public function revokeRole(User $user, Role $role): bool
    {
        return DB::transaction(function() use ($user, $role) {
            return DB::table('user_roles')
                ->where('user_id', $user->id)
                ->where('role_id', $role->id)
                ->delete() > 0;
        });
    }

    public function updateLoginInfo(User $user, array $data): bool
    {
        return $user->update($data);
    }
}

class RoleRepository
{
    public function find(int $id): ?Role
    {
        return Role::find($id);
    }

    public function getPermissions(Role $role): array
    {
        return DB::table('role_permissions')
            ->where('role_id', $role->id)
            ->pluck('permission')
            ->toArray();
    }
}

class PermissionRepository
{
    public function getForUser(User $user): array
    {
        return DB::table('role_permissions')
            ->join('user_roles', 'role_permissions.role_id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $user->id)
            ->pluck('role_permissions.permission')
            ->unique()
            ->values()
            ->toArray();
    }
}

class User
{
    protected $hidden = ['password'];
    
    public function isActive(): bool
    {
        return $this->active && 
               !$this->blocked && 
               ($this->expires_at === null || $this->expires_at->isFuture());
    }
}

class Role
{
    public function getPermissions(): array
    {
        return app(RoleRepository::class)->getPermissions($this);
    }
}

class AuthResult
{
    public User $user;
    public string $token;
    public array $permissions;

    public function __construct(User $user, string $token)
    {
        $this->user = $user;
        $this->token = $token;
        $this->permissions = app(PermissionRepository::class)->getForUser($user);
    }
}
