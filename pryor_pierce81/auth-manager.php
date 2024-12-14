<?php

namespace App\Core\Auth;

use Illuminate\Support\Facades\{DB, Cache};
use App\Core\Security\SecurityManager;
use App\Core\Services\{ValidationService, AuditService};
use App\Core\Interfaces\AuthorizationInterface;
use App\Core\Exceptions\{AuthorizationException, ValidationException};

class AuthorizationManager implements AuthorizationInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private AuditService $audit;
    private array $config;
    private array $roleCache = [];

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        AuditService $audit,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function authorize(int $userId, string $permission, array $context = []): bool
    {
        try {
            $roles = $this->getUserRoles($userId);
            $hasPermission = $this->checkPermission($roles, $permission, $context);
            
            $this->audit->logAuthorizationCheck($userId, $permission, $hasPermission);
            
            return $hasPermission;
        } catch (\Exception $e) {
            $this->handleAuthorizationFailure($userId, $permission, $e);
            return false;
        }
    }

    public function assignRole(int $userId, string $role): void
    {
        DB::transaction(function() use ($userId, $role) {
            $this->validateRole($role);
            $this->createUserRole($userId, $role);
            $this->clearRoleCache($userId);
            $this->audit->logRoleAssignment($userId, $role);
        });
    }

    public function removeRole(int $userId, string $role): void
    {
        DB::transaction(function() use ($userId, $role) {
            $this->validateRole($role);
            $this->deleteUserRole($userId, $role);
            $this->clearRoleCache($userId);
            $this->audit->logRoleRemoval($userId, $role);
        });
    }

    public function createPermission(string $permission, array $attributes): void
    {
        DB::transaction(function() use ($permission, $attributes) {
            $this->validatePermissionData($permission, $attributes);
            $this->storePermission($permission, $attributes);
            $this->clearPermissionCache();
            $this->audit->logPermissionCreation($permission);
        });
    }

    public function assignPermissionToRole(string $role, string $permission): void
    {
        DB::transaction(function() use ($role, $permission) {
            $this->validateRole($role);
            $this->validatePermission($permission);
            $this->createRolePermission($role, $permission);
            $this->clearPermissionCache();
            $this->audit->logPermissionAssignment($role, $permission);
        });
    }

    protected function getUserRoles(int $userId): array
    {
        return Cache::remember(
            "user_roles.$userId",
            $this->config['cache_ttl'],
            fn() => $this->loadUserRoles($userId)
        );
    }

    protected function loadUserRoles(int $userId): array
    {
        return DB::table('user_roles')
            ->where('user_id', $userId)
            ->pluck('role')
            ->all();
    }

    protected function checkPermission(array $roles, string $permission, array $context): bool
    {
        foreach ($roles as $role) {
            if ($this->roleHasPermission($role, $permission)) {
                if ($this->validateContext($role, $permission, $context)) {
                    return true;
                }
            }
        }
        return false;
    }

    protected function roleHasPermission(string $role, string $permission): bool
    {
        return Cache::remember(
            "role_permission.$role.$permission",
            $this->config['cache_ttl'],
            fn() => $this->checkRolePermission($role, $permission)
        );
    }

    protected function checkRolePermission(string $role, string $permission): bool
    {
        return DB::table('role_permissions')
            ->where('role', $role)
            ->where('permission', $permission)
            ->exists();
    }

    protected function validateContext(string $role, string $permission, array $context): bool
    {
        $rules = $this->getContextValidationRules($role, $permission);
        
        foreach ($rules as $rule) {
            if (!$this->evaluateContextRule($rule, $context)) {
                return false;
            }
        }
        
        return true;
    }

    protected function evaluateContextRule(array $rule, array $context): bool
    {
        $type = $rule['type'];
        $value = $context[$rule['key']] ?? null;

        return match($type) {
            'equals' => $value === $rule['value'],
            'contains' => is_array($value) && in_array($rule['value'], $value),
            'range' => $value >= $rule['min'] && $value <= $rule['max'],
            'callback' => call_user_func($rule['callback'], $value),
            default => false
        };
    }

    protected function validateRole(string $role): void
    {
        if (!$this->roleExists($role)) {
            throw new ValidationException("Invalid role: $role");
        }
    }

    protected function validatePermission(string $permission): void
    {
        if (!$this->permissionExists($permission)) {
            throw new ValidationException("Invalid permission: $permission");
        }
    }

    protected function validatePermissionData(string $permission, array $attributes): void
    {
        $rules = [
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'context_rules' => 'array'
        ];

        if (!$this->validator->validate($attributes, $rules)) {
            throw new ValidationException('Invalid permission data');
        }
    }

    protected function createUserRole(int $userId, string $role): void
    {
        DB::table('user_roles')->insert([
            'user_id' => $userId,
            'role' => $role,
            'created_at' => now()
        ]);
    }

    protected function createRolePermission(string $role, string $permission): void
    {
        DB::table('role_permissions')->insert([
            'role' => $role,
            'permission' => $permission,
            'created_at' => now()
        ]);
    }

    protected function clearRoleCache(int $userId): void
    {
        Cache::forget("user_roles.$userId");
        $this->roleCache = [];
    }

    protected function clearPermissionCache(): void
    {
        Cache::tags(['permissions', 'roles'])->flush();
    }

    protected function handleAuthorizationFailure(int $userId, string $permission, \Exception $e): void
    {
        $this->audit->logAuthorizationFailure($userId, $permission, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        if ($this->isSecurityThreat($e)) {
            $this->security->handleSecurityThreat('authorization_failure', [
                'user_id' => $userId,
                'permission' => $permission,
                'error' => $e->getMessage()
            ]);
        }
    }
}
