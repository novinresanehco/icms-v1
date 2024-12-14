<?php

namespace App\Core\Auth;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Events\RbacEvent;
use App\Core\Exceptions\{RbacException, SecurityException};
use Illuminate\Support\Facades\{DB, Log};

class RbacManager implements RbacInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private array $loadedPermissions = [];
    private array $roleHierarchy = [];

    public function __construct(
        SecurityManager $security,
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->initializeRoleHierarchy();
    }

    public function hasPermission(int $userId, string $permission): bool
    {
        return $this->security->executeCriticalOperation(
            function() use ($userId, $permission) {
                $userPermissions = $this->getUserPermissions($userId);
                return $this->checkPermission($permission, $userPermissions);
            },
            ['operation' => 'check_permission']
        );
    }

    public function assignRole(int $userId, string $role): void
    {
        $this->security->executeCriticalOperation(
            function() use ($userId, $role) {
                DB::beginTransaction();
                try {
                    $this->validateRole($role);
                    
                    DB::table('user_roles')->insert([
                        'user_id' => $userId,
                        'role' => $role,
                        'assigned_at' => now()
                    ]);

                    $this->clearUserCache($userId);
                    event(new RbacEvent('role_assigned', $userId, $role));
                    
                    DB::commit();
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw new RbacException('Role assignment failed: ' . $e->getMessage());
                }
            },
            ['operation' => 'assign_role']
        );
    }

    public function revokeRole(int $userId, string $role): void
    {
        $this->security->executeCriticalOperation(
            function() use ($userId, $role) {
                DB::beginTransaction();
                try {
                    DB::table('user_roles')
                        ->where('user_id', $userId)
                        ->where('role', $role)
                        ->delete();

                    $this->clearUserCache($userId);
                    event(new RbacEvent('role_revoked', $userId, $role));
                    
                    DB::commit();
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw new RbacException('Role revocation failed: ' . $e->getMessage());
                }
            },
            ['operation' => 'revoke_role']
        );
    }

    public function createRole(string $role, array $permissions, array $options = []): void
    {
        $this->security->executeCriticalOperation(
            function() use ($role, $permissions, $options) {
                DB::beginTransaction();
                try {
                    $this->validateNewRole($role);
                    $this->validatePermissions($permissions);
                    
                    DB::table('roles')->insert([
                        'name' => $role,
                        'permissions' => json_encode($permissions),
                        'options' => json_encode($options),
                        'created_at' => now()
                    ]);

                    $this->clearRoleCache();
                    event(new RbacEvent('role_created', null, $role));
                    
                    DB::commit();
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw new RbacException('Role creation failed: ' . $e->getMessage());
                }
            },
            ['operation' => 'create_role']
        );
    }

    public function updateRole(string $role, array $permissions, array $options = []): void
    {
        $this->security->executeCriticalOperation(
            function() use ($role, $permissions, $options) {
                DB::beginTransaction();
                try {
                    $this->validatePermissions($permissions);
                    
                    DB::table('roles')
                        ->where('name', $role)
                        ->update([
                            'permissions' => json_encode($permissions),
                            'options' => json_encode($options),
                            'updated_at' => now()
                        ]);

                    $this->clearRoleCache();
                    event(new RbacEvent('role_updated', null, $role));
                    
                    DB::commit();
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw new RbacException('Role update failed: ' . $e->getMessage());
                }
            },
            ['operation' => 'update_role']
        );
    }

    public function deleteRole(string $role): void
    {
        $this->security->executeCriticalOperation(
            function() use ($role) {
                DB::beginTransaction();
                try {
                    $this->validateRoleDeletion($role);
                    
                    DB::table('roles')->where('name', $role)->delete();
                    DB::table('user_roles')->where('role', $role)->delete();

                    $this->clearRoleCache();
                    event(new RbacEvent('role_deleted', null, $role));
                    
                    DB::commit();
                } catch (\Exception $e) {
                    DB::rollBack();
                    throw new RbacException('Role deletion failed: ' . $e->getMessage());
                }
            },
            ['operation' => 'delete_role']
        );
    }

    protected function getUserPermissions(int $userId): array
    {
        return $this->cache->remember(
            "user.{$userId}.permissions",
            3600,
            function() use ($userId) {
                $roles = DB::table('user_roles')
                    ->where('user_id', $userId)
                    ->pluck('role');

                $permissions = [];
                foreach ($roles as $role) {
                    $permissions = array_merge(
                        $permissions,
                        $this->getRolePermissions($role)
                    );
                }

                return array_unique($permissions);
            }
        );
    }

    protected function getRolePermissions(string $role): array
    {
        if (isset($this->loadedPermissions[$role])) {
            return $this->loadedPermissions[$role];
        }

        $permissions = DB::table('roles')
            ->where('name', $role)
            ->value('permissions');

        return $this->loadedPermissions[$role] = json_decode($permissions, true) ?? [];
    }

    protected function checkPermission(string $permission, array $userPermissions): bool
    {
        if (in_array($permission, $userPermissions)) {
            return true;
        }

        foreach ($userPermissions as $userPermission) {
            if ($this->isPermissionIncluded($permission, $userPermission)) {
                return true;
            }
        }

        return false;
    }

    protected function isPermissionIncluded(string $required, string $granted): bool
    {
        if ($granted === '*') {
            return true;
        }

        $requiredParts = explode('.', $required);
        $grantedParts = explode('.', $granted);

        if (count($grantedParts) > count($requiredParts)) {
            return false;
        }

        foreach ($grantedParts as $i => $part) {
            if ($part === '*') {
                return true;
            }
            if ($part !== $requiredParts[$i]) {
                return false;
            }
        }

        return count($requiredParts) === count($grantedParts);
    }

    protected function clearUserCache(int $userId): void
    {
        $this->cache->forget("user.{$userId}.permissions");
    }

    protected function clearRoleCache(): void
    {
        $this->loadedPermissions = [];
        $this->cache->tags(['rbac'])->flush();
    }

    protected function validateRole(string $role): void
    {
        if (!DB::table('roles')->where('name', $role)->exists()) {
            throw new RbacException("Role {$role} does not exist");
        }
    }

    protected function validateNewRole(string $role): void
    {
        if (DB::table('roles')->where('name', $role)->exists()) {
            throw new RbacException("Role {$role} already exists");
        }
    }

    protected function validatePermissions(array $permissions): void
    {
        foreach ($permissions as $permission) {
            if (!preg_match('/^[a-z0-9_.*]+$/', $permission)) {
                throw new RbacException("Invalid permission format: {$permission}");
            }
        }
    }

    protected function validateRoleDeletion(string $role): void
    {
        if ($this->isProtectedRole($role)) {
            throw new RbacException("Cannot delete protected role: {$role}");
        }
    }

    protected function isProtectedRole(string $role): bool
    {
        return in_array($role, ['admin', 'system']);
    }

    protected function initializeRoleHierarchy(): void
    {
        $this->roleHierarchy = config('rbac.role_hierarchy', []);
    }
}
