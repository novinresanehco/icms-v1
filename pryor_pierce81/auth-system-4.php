<?php

namespace App\Core\Auth;

use App\Core\Security\SecurityContext;
use Illuminate\Support\Facades\{Cache, DB};
use App\Core\Contracts\AccessControlInterface;

class AccessControlManager implements AccessControlInterface
{
    private PermissionRegistry $permissions;
    private RoleManager $roles;
    private AuditLogger $audit;
    private array $config;

    public function __construct(
        PermissionRegistry $permissions,
        RoleManager $roles,
        AuditLogger $audit,
        array $config
    ) {
        $this->permissions = $permissions;
        $this->roles = $roles;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function validate(SecurityContext $context, string $permission): bool
    {
        $cacheKey = $this->getValidationCacheKey($context, $permission);
        
        return Cache::remember($cacheKey, 300, function() use ($context, $permission) {
            try {
                $result = $this->validatePermission($context, $permission);
                $this->audit->logAccessAttempt($context, $permission, $result);
                return $result;
            } catch (\Exception $e) {
                $this->audit->logValidationError($context, $permission, $e);
                throw $e;
            }
        });
    }

    public function validateMultiple(SecurityContext $context, array $permissions): bool
    {
        return collect($permissions)->every(fn($permission) => 
            $this->validate($context, $permission)
        );
    }

    public function validateHierarchy(SecurityContext $context, string $permission): bool
    {
        $hierarchy = $this->permissions->getHierarchy($permission);
        return collect($hierarchy)->every(fn($perm) => 
            $this->validate($context, $perm)
        );
    }

    public function getRolePermissions(int $roleId): array
    {
        $cacheKey = "role.permissions.{$roleId}";
        
        return Cache::remember($cacheKey, 3600, function() use ($roleId) {
            return $this->roles->getPermissions($roleId);
        });
    }

    public function checkRateLimit(SecurityContext $context, string $key): bool
    {
        $limitKey = $this->getRateLimitKey($context, $key);
        $attempts = (int)Cache::get($limitKey, 0);
        
        if ($attempts >= $this->config['rate_limit']) {
            $this->audit->logRateLimitExceeded($context, $key);
            return false;
        }

        Cache::put($limitKey, $attempts + 1, 60);
        return true;
    }

    protected function validatePermission(SecurityContext $context, string $permission): bool
    {
        $roles = $context->getRoles();
        
        foreach ($roles as $roleId) {
            $rolePermissions = $this->getRolePermissions($roleId);
            
            if (in_array($permission, $rolePermissions)) {
                return true;
            }

            if ($this->hasWildcardPermission($rolePermissions, $permission)) {
                return true;
            }
        }

        return false;
    }

    protected function hasWildcardPermission(array $rolePermissions, string $permission): bool
    {
        $parts = explode('.', $permission);
        
        while (count($parts) > 0) {
            array_pop($parts);
            $wildcard = implode('.', $parts) . '.*';
            
            if (in_array($wildcard, $rolePermissions)) {
                return true;
            }
        }

        return false;
    }

    private function getValidationCacheKey(SecurityContext $context, string $permission): string
    {
        return sprintf(
            'access.%s.%s.%s',
            $context->getUserId(),
            md5(implode(',', $context->getRoles())),
            md5($permission)
        );
    }

    private function getRateLimitKey(SecurityContext $context, string $key): string
    {
        return sprintf(
            'rate_limit.%s.%s',
            $context->getUserId(),
            md5($key)
        );
    }
}
