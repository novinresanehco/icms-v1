<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\{SecurityManager, AuditLogger};
use App\Core\Exceptions\{AuthorizationException, ValidationException};

class AuthorizationManager implements AuthorizationInterface 
{
    private SecurityManager $security;
    private PermissionRegistry $permissions;
    private RoleManager $roles;
    private AuditLogger $audit;

    public function __construct(
        SecurityManager $security,
        PermissionRegistry $permissions,
        RoleManager $roles,
        AuditLogger $audit
    ) {
        $this->security = $security;
        $this->permissions = $permissions;
        $this->roles = $roles;
        $this->audit = $audit;
    }

    public function authorize(int $userId, string $permission, array $context = []): bool 
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeAuthorization($userId, $permission, $context),
            ['user_id' => $userId, 'permission' => $permission]
        );
    }

    protected function executeAuthorization(int $userId, string $permission, array $context): bool 
    {
        $cacheKey = "auth.{$userId}.{$permission}";

        return Cache::remember($cacheKey, 300, function() use ($userId, $permission, $context) {
            $user = $this->roles->getUserWithRoles($userId);
            
            if (!$user) {
                $this->audit->logAuthFailure($userId, $permission, 'User not found');
                return false;
            }

            $hasPermission = $this->validatePermission($user, $permission, $context);
            
            $this->audit->logAuthAttempt($userId, $permission, $hasPermission);
            
            return $hasPermission;
        });
    }

    protected function validatePermission($user, string $permission, array $context): bool 
    {
        DB::beginTransaction();
        
        try {
            $roles = $this->roles->getUserRoles($user->id);
            
            foreach ($roles as $role) {
                if ($this->permissions->roleHasPermission($role->id, $permission)) {
                    if ($this->validateContextualPermission($user, $permission, $context)) {
                        DB::commit();
                        return true;
                    }
                }
            }
            
            DB::commit();
            return false;
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Permission validation failed', [
                'user' => $user->id,
                'permission' => $permission,
                'error' => $e->getMessage()
            ]);
            throw new AuthorizationException('Permission validation failed', 0, $e);
        }
    }

    protected function validateContextualPermission($user, string $permission, array $context): bool 
    {
        return $this->permissions->validateContextualPermission($user, $permission, $context);
    }
}

class PermissionRegistry
{
    public function roleHasPermission(int $roleId, string $permission): bool 
    {
        return Cache::remember("role.{$roleId}.{$permission}", 3600, function() use ($roleId, $permission) {
            return DB::table('role_permissions')
                ->where('role_id', $roleId)
                ->where('permission', $permission)
                ->exists();
        });
    }

    public function validateContextualPermission($user, string $permission, array $context): bool 
    {
        $validator = $this->getContextValidator($permission);
        return $validator ? $validator->validate($user, $context) : true;
    }

    protected function getContextValidator(string $permission): ?PermissionValidator 
    {
        return match($permission) {
            'content.edit' => new ContentPermissionValidator(),
            'user.manage' => new UserPermissionValidator(),
            'system.admin' => new SystemPermissionValidator(),
            default => null
        };
    }
}

class RoleManager
{
    public function getUserWithRoles(int $userId): ?User 
    {
        return Cache::remember("user.{$userId}.roles", 3600, function() use ($userId) {
            return User::with('roles')->find($userId);
        });
    }

    public function getUserRoles(int $userId): Collection 
    {
        return Cache::remember("user.{$userId}.roles.list", 3600, function() use ($userId) {
            return DB::table('user_roles')
                ->join('roles', 'roles.id', '=', 'user_roles.role_id')
                ->where('user_id', $userId)
                ->select('roles.*')
                ->get();
        });
    }

    public function assignRole(int $userId, int $roleId): void 
    {
        DB::transaction(function() use ($userId, $roleId) {
            DB::table('user_roles')->insert([
                'user_id' => $userId,
                'role_id' => $roleId
            ]);
            
            $this->clearUserRoleCache($userId);
        });
    }

    public function removeRole(int $userId, int $roleId): void 
    {
        DB::transaction(function() use ($userId, $roleId) {
            DB::table('user_roles')
                ->where('user_id', $userId)
                ->where('role_id', $roleId)
                ->delete();
            
            $this->clearUserRoleCache($userId);
        });
    }

    protected function clearUserRoleCache(int $userId): void 
    {
        Cache::forget("user.{$userId}.roles");
        Cache::forget("user.{$userId}.roles.list");
    }
}

abstract class PermissionValidator
{
    abstract public function validate($user, array $context): bool;
    
    protected function validateResourceOwnership($user, $resourceId, string $type): bool 
    {
        return Cache::remember(
            "ownership.{$user->id}.{$type}.{$resourceId}",
            300,
            fn() => DB::table($type)
                ->where('id', $resourceId)
                ->where('user_id', $user->id)
                ->exists()
        );
    }
}

class ContentPermissionValidator extends PermissionValidator
{
    public function validate($user, array $context): bool 
    {
        if (empty($context['content_id'])) {
            return false;
        }

        return $this->validateResourceOwnership($user, $context['content_id'], 'contents');
    }
}

class UserPermissionValidator extends PermissionValidator
{
    public function validate($user, array $context): bool 
    {
        if (empty($context['target_user_id'])) {
            return false;
        }

        return $user->hasRole('admin') || $context['target_user_id'] === $user->id;
    }
}

class SystemPermissionValidator extends PermissionValidator
{
    public function validate($user, array $context): bool 
    {
        return $user->hasRole('admin');
    }
}

interface AuthorizationInterface
{
    public function authorize(int $userId, string $permission, array $context = []): bool;
}
