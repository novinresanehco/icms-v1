<?php

namespace App\Core\Permission;

use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Monitoring\MonitoringService;
use Illuminate\Support\Facades\DB;

class PermissionManager
{
    private SecurityManager $security;
    private ValidationService $validator;
    private MonitoringService $monitor;
    private const CACHE_TTL = 3600;

    /**
     * Check user permissions
     */
    public function checkPermission(User $user, string $permission): bool
    {
        $operationId = $this->monitor->startOperation('permission:check');
        
        try {
            // Validate permission format
            $this->validator->validatePermission($permission);
            
            // Get user roles
            $roles = $this->getUserRoles($user);
            
            // Check role permissions
            foreach ($roles as $role) {
                if ($this->roleHasPermission($role, $permission)) {
                    return true;
                }
            }
            
            // Log access attempt
            $this->monitor->logPermissionCheck($user, $permission, false);
            
            return false;
            
        } catch (\Throwable $e) {
            $this->handlePermissionFailure($e, $user, $permission);
            return false;
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    /**
     * Grant permission to role
     */
    public function grantPermission(Role $role, string $permission): void
    {
        $this->executePermissionOperation('permission:grant', function() use ($role, $permission) {
            // Validate permission
            $this->validator->validatePermission($permission);
            
            // Grant permission
            $role->permissions()->create(['name' => $permission]);
            
            // Clear role permissions cache
            $this->clearRolePermissionsCache($role);
            
            // Log grant
            $this->monitor->logPermissionGrant($role, $permission);
        });
    }

    /**
     * Revoke permission from role
     */
    public function revokePermission(Role $role, string $permission): void
    {
        $this->executePermissionOperation('permission:revoke', function() use ($role, $permission) {
            // Validate permission
            $this->validator->validatePermission($permission);
            
            // Revoke permission
            $role->permissions()->where('name', $permission)->delete();
            
            // Clear role permissions cache
            $this->clearRolePermissionsCache($role);
            
            // Log revoke
            $this->monitor->logPermissionRevoke($role, $permission);
        });
    }

    /**
     * Get user roles with caching
     */
    private function getUserRoles(User $user): array
    {
        return Cache::remember("user:{$user->id}:roles", self::CACHE_TTL, function() use ($user) {
            return $user->roles()->with('permissions')->get()->toArray();
        });
    }

    /**
     * Check if role has permission
     */
    private function roleHasPermission(array $role, string $permission): bool
    {
        return Cache::remember("role:{$role['id']}:permission:{$permission}", self::CACHE_TTL, 
            function() use ($role, $permission) {
                return collect($role['permissions'])->contains('name', $permission);
            });
    }

    /**
     * Execute permission operation with protection
     */
    private function executePermissionOperation(string $operation, callable $action): void
    {
        $operationId = $this->monitor->startOperation($operation);
        
        try {
            // Verify security context
            $this->security->validateContext();
            
            DB::beginTransaction();
            
            // Execute operation
            $action();
            
            DB::commit();
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handlePermissionFailure($e, $operation);
            throw $e;
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    /**
     * Clear role permissions cache
     */
    private function clearRolePermissionsCache(Role $role): void
    {
        Cache::tags(["role:{$role->id}", 'permissions'])->flush();
    }

    /**
     * Handle permission operation failures
     */
    private function handlePermissionFailure(\Throwable $e, ...$context): void
    {
        $this->monitor->recordFailure('permission_operation', [
            'error' => $e->getMessage(),
            'context' => $context,
            'trace' => $e->getTraceAsString()
        ]);

        $this->security->notifyAdministrators('permission_failure', [
            'error' => $e->getMessage(),
            'context' => $context
        ]);
    }
}
