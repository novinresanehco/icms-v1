<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{Hash, Cache};
use App\Core\Security\SecurityManager;
use App\Core\Exceptions\{AuthException, SecurityException};
use App\Core\Auth\Models\{User, Role, Permission};

class AuthenticationService
{
    private SecurityManager $security;
    private RoleManager $roles;
    private PermissionManager $permissions;

    public function authenticate(array $credentials): User
    {
        return $this->security->executeCriticalOperation('authenticate', function() use ($credentials) {
            // Validate credentials
            $user = User::where('email', $credentials['email'])->first();

            if (!$user || !Hash::check($credentials['password'], $user->password)) {
                throw new AuthException('Invalid credentials');
            }

            // Verify 2FA if enabled
            if ($user->hasTwoFactorEnabled()) {
                $this->verifyTwoFactor($user, $credentials['two_factor_code'] ?? null);
            }

            // Check account status
            if (!$user->isActive()) {
                throw new AuthException('Account is not active');
            }

            // Log successful authentication
            $this->logAuthenticationSuccess($user);

            return $user;
        });
    }

    public function verifyPermission(User $user, string $permission): bool
    {
        return Cache::tags(['permissions', "user:{$user->id}"])->remember(
            "permission:{$permission}",
            now()->addMinutes(60),
            fn() => $this->computePermission($user, $permission)
        );
    }

    private function computePermission(User $user, string $permission): bool
    {
        // Check direct permissions
        if ($user->hasDirectPermission($permission)) {
            return true;
        }

        // Check role permissions
        foreach ($user->roles as $role) {
            if ($role->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    private function verifyTwoFactor(User $user, ?string $code): void
    {
        if (!$code || !$user->verifyTwoFactorCode($code)) {
            throw new AuthException('Invalid two-factor code');
        }
    }

    private function logAuthenticationSuccess(User $user): void
    {
        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => request()->ip()
        ]);
    }
}

class RoleManager
{
    private SecurityManager $security;
    private PermissionManager $permissions;

    public function assignRole(User $user, Role $role): void
    {
        $this->security->executeCriticalOperation('role_assignment', function() use ($user, $role) {
            // Verify role assignment is allowed
            $this->verifyRoleAssignment($user, $role);

            // Assign role
            $user->roles()->attach($role->id);

            // Clear permission cache
            $this->clearPermissionCache($user);

            // Log role assignment
            $this->logRoleAssignment($user, $role);
        });
    }

    public function createRole(array $data): Role
    {
        return $this->security->executeCriticalOperation('role_creation', function() use ($data) {
            // Create role
            $role = Role::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'level' => $data['level'] ?? 0
            ]);

            // Assign permissions
            if (isset($data['permissions'])) {
                $role->permissions()->attach($data['permissions']);
            }

            return $role;
        });
    }

    private function verifyRoleAssignment(User $user, Role $role): void
    {
        // Verify user doesn't already have role
        if ($user->hasRole($role)) {
            throw new AuthException('User already has this role');
        }

        // Verify role level constraints
        if (!$this->isRoleLevelAllowed($user, $role)) {
            throw new SecurityException('Role level not allowed for user');
        }
    }

    private function isRoleLevelAllowed(User $user, Role $role): bool
    {
        // Implement role hierarchy/level checks
        return true;
    }

    private function clearPermissionCache(User $user): void
    {
        Cache::tags(['permissions', "user:{$user->id}"])->flush();
    }

    private function logRoleAssignment(User $user, Role $role): void
    {
        // Log role assignment
    }
}

class PermissionManager
{
    private SecurityManager $security;

    public function createPermission(array $data): Permission
    {
        return $this->security->executeCriticalOperation('permission_creation', function() use ($data) {
            return Permission::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'category' => $data['category'] ?? null
            ]);
        });
    }

    public function assignPermission(Role $role, Permission $permission): void
    {
        $this->security->executeCriticalOperation('permission_assignment', function() use ($role, $permission) {
            // Verify permission assignment
            $this->verifyPermissionAssignment($role, $permission);

            // Assign permission
            $role->permissions()->attach($permission->id);

            // Clear related caches
            $this->clearPermissionCaches($role);
        });
    }

    private function verifyPermissionAssignment(Role $role, Permission $permission): void
    {
        if ($role->hasPermission($permission)) {
            throw new AuthException('Role already has this permission');
        }
    }

    private function clearPermissionCaches(Role $role): void
    {
        // Clear role permissions cache
        Cache::tags(['permissions', "role:{$role->id}"])->flush();

        // Clear cache for all users with this role
        foreach ($role->users as $user) {
            Cache::tags("user:{$user->id}")->flush();
        }
    }
}
