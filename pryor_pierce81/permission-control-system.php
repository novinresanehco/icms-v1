<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{DB, Cache};
use App\Core\Services\{ValidationService, AuditLogger};
use App\Core\Exceptions\{SecurityException, ValidationException};

class PermissionManager
{
    private ValidationService $validator;
    private AuditLogger $auditLogger;
    private array $securityConfig;
    private array $permissionCache = [];

    public function __construct(
        ValidationService $validator,
        AuditLogger $auditLogger,
        array $securityConfig
    ) {
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
        $this->securityConfig = $securityConfig;
    }

    public function validatePermission(int $userId, string $permission, array $context = []): bool
    {
        try {
            $this->validateRequest($userId, $permission, $context);
            
            $hasPermission = Cache::remember(
                "permission:{$userId}:{$permission}",
                $this->securityConfig['permission_cache_ttl'],
                fn() => $this->checkPermission($userId, $permission, $context)
            );

            $this->logAccessAttempt($userId, $permission, $hasPermission, $context);

            return $hasPermission;

        } catch (\Exception $e) {
            $this->handleValidationFailure($e, $userId, $permission, $context);
            throw $e;
        }
    }

    public function assignRole(int $userId, string $role, array $context = []): void
    {
        DB::transaction(function() use ($userId, $role, $context) {
            $this->validateRoleAssignment($userId, $role, $context);
            
            DB::table('user_roles')->insert([
                'user_id' => $userId,
                'role' => $role,
                'assigned_by' => $context['admin_id'] ?? null,
                'created_at' => now()
            ]);

            $this->invalidateUserPermissions($userId);
            
            $this->auditLogger->logRoleAssignment([
                'user_id' => $userId,
                'role' => $role,
                'context' => $context
            ]);
        });
    }

    public function revokeRole(int $userId, string $role, array $context = []): void
    {
        DB::transaction(function() use ($userId, $role, $context) {
            $this->validateRoleRevocation($userId, $role, $context);
            
            DB::table('user_roles')
                ->where('user_id', $userId)
                ->where('role', $role)
                ->delete();

            $this->invalidateUserPermissions($userId);
            
            $this->auditLogger->logRoleRevocation([
                'user_id' => $userId,
                'role' => $role,
                'context' => $context
            ]);
        });
    }

    protected function checkPermission(int $userId, string $permission, array $context): bool
    {
        $roles = $this->getUserRoles($userId);
        
        foreach ($roles as $role) {
            if ($this->roleHasPermission($role, $permission)) {
                if ($this->validateContextualPermission($userId, $role, $permission, $context)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function validateRequest(int $userId, string $permission, array $context): void
    {
        if (!$this->validator->validatePermissionCheck($userId, $permission, $context)) {
            throw new ValidationException('Invalid permission check request');
        }

        if ($this->isRateLimited($userId)) {
            throw new SecurityException('Permission check rate limit exceeded');
        }
    }

    protected function validateRoleAssignment(int $userId, string $role, array $context): void
    {
        if (!$this->validator->validateRoleAssignment($userId, $role, $context)) {
            throw new ValidationException('Invalid role assignment');
        }

        if (!$this->hasAssignmentAuthority($context)) {
            throw new SecurityException('Insufficient authority for role assignment');
        }
    }

    protected function validateRoleRevocation(int $userId, string $role, array $context): void
    {
        if (!$this->validator->validateRoleRevocation($userId, $role, $context)) {
            throw new ValidationException('Invalid role revocation');
        }

        if (!$this->hasRevocationAuthority($context)) {
            throw new SecurityException('Insufficient authority for role revocation');
        }
    }

    protected function validateContextualPermission(
        int $userId, 
        string $role, 
        string $permission, 
        array $context
    ): bool {
        return DB::table('contextual_permissions')
            ->where('user_id', $userId)
            ->where('role', $role)
            ->where('permission', $permission)
            ->where('context_type', $context['type'] ?? null)
            ->where('context_id', $context['id'] ?? null)
            ->exists();
    }

    protected function getUserRoles(int $userId): array
    {
        if (!isset($this->permissionCache[$userId])) {
            $this->permissionCache[$userId] = DB::table('user_roles')
                ->where('user_id', $userId)
                ->pluck('role')
                ->toArray();
        }

        return $this->permissionCache[$userId];
    }

    protected function roleHasPermission(string $role, string $permission): bool
    {
        return DB::table('role_permissions')
            ->where('role', $role)
            ->where('permission', $permission)
            ->exists();
    }

    protected function invalidateUserPermissions(int $userId): void
    {
        unset($this->permissionCache[$userId]);
        Cache::tags("user:{$userId}")->flush();
    }

    protected function logAccessAttempt(
        int $userId, 
        string $permission, 
        bool $granted, 
        array $context
    ): void {
        $this->auditLogger->logPermissionCheck([
            'user_id' => $userId,
            'permission' => $permission,
            'granted' => $granted,
            'context' => $context,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);
    }

    protected function handleValidationFailure(
        \Exception $e, 
        int $userId, 
        string $permission, 
        array $context
    ): void {
        $this->auditLogger->logPermissionFailure([
            'user_id' => $userId,
            'permission' => $permission,
            'error' => $e->getMessage(),
            'context' => $context
        ]);
    }

    protected function isRateLimited(int $userId): bool
    {
        $key = "permission_checks:{$userId}";
        $attempts = Cache::get($key, 0);
        
        if ($attempts >= $this->securityConfig['max_permission_checks']) {
            return true;
        }

        Cache::increment($key);
        Cache::expire($key, 60);
        
        return false;
    }
}
