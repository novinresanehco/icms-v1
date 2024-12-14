<?php

namespace App\Core\Auth;

use App\Core\Security\SecurityContext;
use App\Core\Monitoring\SystemMonitor;
use App\Core\Cache\CacheManager;

class AuthorizationManager implements AuthorizationInterface
{
    private SecurityContext $security;
    private SystemMonitor $monitor;
    private CacheManager $cache;
    private array $config;

    public function __construct(
        SecurityContext $security,
        SystemMonitor $monitor,
        CacheManager $cache,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function authorize(string $permission, array $context = []): bool
    {
        $monitoringId = $this->monitor->startOperation('authorization');
        
        try {
            $user = $this->security->getCurrentUser();
            
            if (!$user) {
                return false;
            }

            $roles = $this->getUserRoles($user->id);
            $permissions = $this->getRolePermissions($roles);
            
            return $this->checkPermission($permission, $permissions, $context);
            
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function checkRole(string $role): bool
    {
        $monitoringId = $this->monitor->startOperation('role_check');
        
        try {
            $user = $this->security->getCurrentUser();
            
            if (!$user) {
                return false;
            }

            $userRoles = $this->getUserRoles($user->id);
            
            return in_array($role, $userRoles);
            
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function validateAccess(string $resource, string $action): bool
    {
        $monitoringId = $this->monitor->startOperation('access_validation');
        
        try {
            $permission = "{$resource}.{$action}";
            $context = [
                'resource' => $resource,
                'action' => $action,
                'ip' => request()->ip(),
                'time' => time()
            ];

            return $this->authorize($permission, $context);
            
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function getUserRoles(int $userId): array
    {
        $cacheKey = $this->getRolesCacheKey($userId);
        
        return $this->cache->remember($cacheKey, function() use ($userId) {
            return UserRole::where('user_id', $userId)
                         ->pluck('role_id')
                         ->toArray();
        });
    }

    private function getRolePermissions(array $roles): array
    {
        $permissions = [];
        
        foreach ($roles as $roleId) {
            $rolePermissions = $this->getRolePermissionsByCache($roleId);
            $permissions = array_merge($permissions, $rolePermissions);
        }
        
        return array_unique($permissions);
    }

    private function getRolePermissionsByCache(int $roleId): array
    {
        $cacheKey = $this->getPermissionsCacheKey($roleId);
        
        return $this->cache->remember($cacheKey, function() use ($roleId) {
            return RolePermission::where('role_id', $roleId)
                                ->pluck('permission')
                                ->toArray();
        });
    }

    private function checkPermission(
        string $permission,
        array $permissions,
        array $context
    ): bool {
        if (in_array($permission, $permissions)) {
            return $this->validateContext($permission, $context);
        }

        foreach ($permissions as $userPermission) {
            if ($this->isWildcardMatch($userPermission, $permission)) {
                return $this->validateContext($userPermission, $context);
            }
        }

        return false;
    }

    private function validateContext(string $permission, array $context): bool
    {
        $rules = $this->getContextRules($permission);
        
        if (empty($rules)) {
            return true;
        }

        foreach ($rules as $rule) {
            if (!$this->evaluateContextRule($rule, $context)) {
                return false;
            }
        }

        return true;
    }

    private function getContextRules(string $permission): array
    {
        return $this->config['context_rules'][$permission] ?? [];
    }

    private function evaluateContextRule(array $rule, array $context): bool
    {
        $type = $rule['type'];
        $params = $rule['params'];

        return match ($type) {
            'time_range' => $this->checkTimeRange($context['time'], $params),
            'ip_range' => $this->checkIpRange($context['ip'], $params),
            'resource_owner' => $this->checkResourceOwner($context['resource'], $params),
            default => false
        };
    }

    private function isWildcardMatch(string $pattern, string $permission): bool
    {
        $pattern = preg_quote($pattern, '/');
        $pattern = str_replace('\*', '.*', $pattern);
        return (bool) preg_match('/^' . $pattern . '$/', $permission);
    }

    private function getRolesCacheKey(int $userId): string
    {
        return "user_roles:{$userId}";
    }

    private function getPermissionsCacheKey(int $roleId): string
    {
        return "role_permissions:{$roleId}";
    }
}
