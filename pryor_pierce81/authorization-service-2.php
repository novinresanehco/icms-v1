<?php

namespace App\Core\Auth;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManagerInterface;
use App\Core\Exception\{AuthorizationException, SecurityException};
use Psr\Log\LoggerInterface;

class AuthorizationService implements AuthorizationInterface
{
    private SecurityManagerInterface $security;
    private CacheManagerInterface $cache;
    private LoggerInterface $logger;
    private PermissionRegistryInterface $permissions;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        CacheManagerInterface $cache,
        LoggerInterface $logger,
        PermissionRegistryInterface $permissions,
        array $config = []
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->logger = $logger;
        $this->permissions = $permissions;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function authorize(string $userId, string $permission, array $context = []): bool
    {
        try {
            // Validate inputs
            $this->validatePermission($permission);
            
            // Check cache
            $cacheKey = $this->getPermissionCacheKey($userId, $permission);
            if (($cached = $this->cache->get($cacheKey)) !== null) {
                return $cached;
            }

            // Get user roles
            $roles = $this->security->getUserRoles($userId);
            
            // Check role permissions
            foreach ($roles as $role) {
                if ($this->checkRolePermission($role, $permission, $context)) {
                    $this->cache->set($cacheKey, true, $this->config['cache_ttl']);
                    return true;
                }
            }

            $this->cache->set($cacheKey, false, $this->config['cache_ttl']);
            return false;

        } catch (\Exception $e) {
            $this->logger->error('Authorization check failed', [
                'user_id' => $userId,
                'permission' => $permission,
                'error' => $e->getMessage()
            ]);
            throw new AuthorizationException('Authorization check failed', 0, $e);
        }
    }

    public function validateAccess(string $userId, string $permission, array $context = []): void
    {
        if (!$this->authorize($userId, $permission, $context)) {
            throw new AuthorizationException('Access denied');
        }
    }

    public function grantPermission(string $roleId, string $permission): void
    {
        try {
            $this->validatePermission($permission);
            $this->permissions->grantPermission($roleId, $permission);
            $this->clearPermissionCache($roleId);

        } catch (\Exception $e) {
            $this->logger->error('Permission grant failed', [
                'role_id' => $roleId,
                'permission' => $permission,
                'error' => $e->getMessage()
            ]);
            throw new AuthorizationException('Permission grant failed', 0, $e);
        }
    }

    public function revokePermission(string $roleId, string $permission): void
    {
        try {
            $this->permissions->revokePermission($roleId, $permission);
            $this->clearPermissionCache($roleId);

        } catch (\Exception $e) {
            $this->logger->error('Permission revoke failed', [
                'role_id' => $roleId,
                'permission' => $permission,
                'error' => $e->getMessage()
            ]);
            throw new AuthorizationException('Permission revoke failed', 0, $e);
        }
    }

    private function checkRolePermission(string $roleId, string $permission, array $context): bool
    {
        $rolePermissions = $this->permissions->getRolePermissions($roleId);
        
        foreach ($rolePermissions as $rolePermission) {
            if ($this->permissionMatches($rolePermission, $permission)) {
                return $this->validateContext($rolePermission, $context);
            }
        }

        return false;
    }

    private function permissionMatches(string $rolePermission, string $requestedPermission): bool
    {
        return $rolePermission === $requestedPermission || 
               $rolePermission === '*' ||
               (str_ends_with($rolePermission, '*') && 
                str_starts_with($requestedPermission, rtrim($rolePermission, '*')));
    }

    private function validateContext(string $permission, array $context): bool
    {
        $constraints = $this->permissions->getPermissionConstraints($permission);
        
        foreach ($constraints as $key => $constraint) {
            if (!isset($context[$key]) || !$constraint->validate($context[$key])) {
                return false;
            }
        }

        return true;
    }

    private function validatePermission(string $permission): void
    {
        if (!preg_match('/^[a-zA-Z0-9:_\-\*]+$/', $permission)) {
            throw new SecurityException('Invalid permission format');
        }
    }

    private function getPermissionCacheKey(string $userId, string $permission): string
    {
        return "auth_permission:{$userId}:{$permission}";
    }

    private function clearPermissionCache(string $roleId): void
    {
        $this->cache->deletePattern("auth_permission:*");
    }

    private function getDefaultConfig(): array
    {
        return [
            'cache_ttl' => 300,
            'max_role_depth' => 5
        ];
    }
}
