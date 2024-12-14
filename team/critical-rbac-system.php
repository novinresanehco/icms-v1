<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Exceptions\{AuthorizationException, SecurityException};

class AccessControlManager implements AccessControlInterface
{
    private PermissionRegistry $permissions;
    private SecurityManager $security;
    private AuditLogger $audit;
    private array $config;

    public function __construct(
        PermissionRegistry $permissions,
        SecurityManager $security,
        AuditLogger $audit,
        array $config
    ) {
        $this->permissions = $permissions;
        $this->security = $security;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function validateAccess(string $resource, string $action, SecurityContext $context): bool
    {
        $startTime = microtime(true);
        $cacheKey = "access:{$context->getUserId()}:{$resource}:{$action}";

        try {
            if ($cached = Cache::get($cacheKey)) {
                $this->audit->logAccessAttempt($context, $resource, $action, true);
                return $cached === true;
            }

            $this->validateSecurityContext($context);
            $this->checkRateLimit($context);
            $this->validateSession($context);

            $hasPermission = $this->checkPermission($context->getUserId(), $resource, $action);
            
            if ($hasPermission) {
                Cache::put($cacheKey, true, $this->config['cache_ttl'] ?? 300);
            }

            $this->audit->logAccessAttempt($context, $resource, $action, $hasPermission);
            return $hasPermission;

        } catch (\Throwable $e) {
            $this->handleAccessFailure($e, $context, $resource, $action);
            throw $e;
        } finally {
            $this->recordMetrics($startTime, $resource, $action, $context);
        }
    }

    public function assignRole(int $userId, string $role): void
    {
        $this->security->executeCriticalOperation(
            function() use ($userId, $role) {
                DB::transaction(function() use ($userId, $role) {
                    $this->validateRole($role);
                    $this->revokeExistingRoles($userId);
                    $this->createRoleAssignment($userId, $role);
                    $this->invalidateAccessCache($userId);
                });
            },
            new SecurityContext('access.assign_role', ['user_id' => $userId, 'role' => $role])
        );
    }

    public function grantPermission(string $role, string $permission): void
    {
        $this->security->executeCriticalOperation(
            function() use ($role, $permission) {
                DB::transaction(function() use ($role, $permission) {
                    $this->validateRole($role);
                    $this->validatePermission($permission);
                    $this->createPermissionGrant($role, $permission);
                    $this->invalidateRoleCache($role);
                });
            },
            new SecurityContext('access.grant_permission', ['role' => $role, 'permission' => $permission])
        );
    }

    private function checkPermission(int $userId, string $resource, string $action): bool
    {
        $roles = $this->getUserRoles($userId);
        $requiredPermissions = $this->permissions->getRequiredPermissions($resource, $action);

        foreach ($roles as $role) {
            $rolePermissions = $this->getRolePermissions($role);
            if ($this->hasRequiredPermissions($rolePermissions, $requiredPermissions)) {
                return true;
            }
        }

        return false;
    }

    private function validateSecurityContext(SecurityContext $context): void
    {
        if (!$context->isValid()) {
            throw new SecurityException('Invalid security context');
        }

        if ($context->isExpired()) {
            throw new SecurityException('Security context expired');
        }

        if (!$this->validateIP($context->getIpAddress())) {
            throw new SecurityException('IP address blocked');
        }
    }

    private function checkRateLimit(SecurityContext $context): void
    {
        $key = "ratelimit:{$context->getUserId()}";
        $attempts = Cache::increment($key);

        if ($attempts === 1) {
            Cache::expire($key, 60);
        }

        if ($attempts > ($this->config['rate_limit'] ?? 100)) {
            throw new SecurityException('Rate limit exceeded');
        }
    }

    private function validateSession(SecurityContext $context): void
    {
        if (!$this->security->validateSession($context->getSessionId())) {
            throw new SecurityException('Invalid session');
        }
    }

    private function getUserRoles(int $userId): array
    {
        return Cache::remember(
            "user_roles:$userId",
            $this->config['cache_ttl'] ?? 300,
            fn() => DB::table('user_roles')
                ->where('user_id', $userId)
                ->pluck('role')
                ->all()
        );
    }

    private function getRolePermissions(string $role): array
    {
        return Cache::remember(
            "role_permissions:$role",
            $this->config['cache_ttl'] ?? 300,
            fn() => DB::table('role_permissions')
                ->where('role', $role)
                ->pluck('permission')
                ->all()
        );
    }

    private function handleAccessFailure(\Throwable $e, SecurityContext $context, string $resource, string $action): void
    {
        $this->audit->logSecurityEvent(
            'access_failure',
            [
                'error' => $e->getMessage(),
                'context' => $context,
                'resource' => $resource,
                'action' => $action
            ]
        );

        if ($e instanceof SecurityException) {
            $this->security->handleSecurityViolation($context);
        }
    }

    private function recordMetrics(float $startTime, string $resource, string $action, SecurityContext $context): void
    {
        $duration = microtime(true) - $startTime;
        $this->audit->recordAccessMetrics([
            'duration' => $duration,
            'resource' => $resource,
            'action' => $action,
            'user_id' => $context->getUserId(),
            'timestamp' => time()
        ]);
    }
}
