<?php

namespace App\Security\Access;

class CriticalPermissionManager
{
    private PermissionRegistry $registry;
    private RoleRepository $roles;
    private AuditLogger $logger;
    private CacheManager $cache;

    public function validateAccess(User $user, string $resource, string $action): bool
    {
        $cacheKey = "permission.{$user->id}.{$resource}.{$action}";
        
        return $this->cache->remember($cacheKey, function() use ($user, $resource, $action) {
            try {
                // Check direct permissions
                if ($this->hasDirectPermission($user, $resource, $action)) {
                    return true;
                }

                // Check role-based permissions
                if ($this->hasRolePermission($user, $resource, $action)) {
                    return true;
                }

                $this->logger->logAccessDenied($user, $resource, $action);
                return false;

            } catch (\Exception $e) {
                $this->logger->logError('Permission check failed', [
                    'user' => $user->id,
                    'resource' => $resource,
                    'action' => $action,
                    'error' => $e->getMessage()
                ]);
                return false;
            }
        });
    }

    private function hasDirectPermission(User $user, string $resource, string $action): bool
    {
        $permission = $this->registry->getPermission($resource, $action);
        return $user->hasPermission($permission);
    }

    private function hasRolePermission(User $user, string $resource, string $action): bool
    {
        foreach ($user->roles as $role) {
            if ($this->roleHasPermission($role, $resource, $action)) {
                return true;
            }
        }
        return false;
    }

    private function roleHasPermission(Role $role, string $resource, string $action): bool
    {
        $permission = $this->registry->getPermission($resource, $action);
        return $role->hasPermission($permission);
    }
}

class AccessControl implements AccessControlInterface
{
    private CriticalPermissionManager $permissions;
    private SecurityManager $security;
    private AuditLogger $logger;
    private MetricsCollector $metrics;

    public function authorize(Request $request): bool
    {
        $startTime = microtime(true);
        
        try {
            $user = $this->security->getCurrentUser();
            if (!$user) {
                throw new UnauthorizedException('User not authenticated');
            }

            $resource = $request->getResource();
            $action = $request->getAction();

            $authorized = $this->permissions->validateAccess($user, $resource, $action);
            
            $this->logAccessAttempt($user, $resource, $action, $authorized);
            $this->recordMetrics($startTime);

            return $authorized;

        } catch (\Exception $e) {
            $this->logger->logError('Authorization failed', [
                'request' => $request,
                'error' => $e->getMessage()
            ]);
            throw new AuthorizationException('Access control failed', 0, $e);
        }
    }

    private function logAccessAttempt(User $user, string $resource, string $action, bool $authorized): void
    {
        $this->logger->logAccessAttempt([
            'user_id' => $user->id,
            'resource' => $resource,
            'action' => $action,
            'authorized' => $authorized,
            'timestamp' => microtime(true),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent()
        ]);
    }

    private function recordMetrics(float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        $this->metrics->record('access_control.duration', $duration);
    }
}

class RoleManager 
{
    private RoleRepository $roles;
    private PermissionRegistry $permissions;
    private AuditLogger $logger;
    private CacheManager $cache;

    public function assignRole(User $user, Role $role): void
    {
        DB::transaction(function() use ($user, $role) {
            $user->roles()->attach($role->id);
            
            $this->cache->tags(['permissions', "user.{$user->id}"])->flush();
            
            $this->logger->logRoleAssignment($user, $role);
        });
    }

    public function revokeRole(User $user, Role $role): void
    {
        DB::transaction(function() use ($user, $role) {
            $user->roles()->detach($role->id);
            
            $this->cache->tags(['permissions', "user.{$user->id}"])->flush();
            
            $this->logger->logRoleRevocation($user, $role);
        });
    }

    public function updateRolePermissions(Role $role, array $permissions): void
    {
        DB::transaction(function() use ($role, $permissions) {
            $role->permissions()->sync($permissions);
            
            $this->cache->tags(['permissions'])->flush();
            
            $this->logger->logRolePermissionUpdate($role, $permissions);
        });
    }
}
