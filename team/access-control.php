namespace App\Core\Security;

class AccessControl implements AccessControlInterface
{
    private PermissionRegistry $permissions;
    private RoleManager $roles;
    private AuditLogger $logger;
    private CacheManager $cache;
    private MetricsCollector $metrics;
    private AccessConfig $config;

    public function __construct(
        PermissionRegistry $permissions,
        RoleManager $roles,
        AuditLogger $logger,
        CacheManager $cache,
        MetricsCollector $metrics,
        AccessConfig $config
    ) {
        $this->permissions = $permissions;
        $this->roles = $roles;
        $this->logger = $logger;
        $this->cache = $cache;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function hasPermission(SecurityContext $context, string $permission): bool
    {
        $startTime = microtime(true);
        $cacheKey = $this->generateCacheKey($context, $permission);

        try {
            if ($cachedResult = $this->getCachedPermission($cacheKey)) {
                return $cachedResult;
            }

            $user = $context->getUser();
            $roles = $this->roles->getUserRoles($user);
            
            $hasPermission = $this->checkPermission($roles, $permission);
            
            $this->cachePermissionResult($cacheKey, $hasPermission);
            $this->logAccessCheck($context, $permission, $hasPermission);
            $this->recordMetrics($startTime, $hasPermission);

            return $hasPermission;

        } catch (\Exception $e) {
            $this->handleAccessFailure($e, $context, $permission);
            throw new AccessControlException(
                'Permission check failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    public function checkRateLimit(SecurityContext $context, string $key): bool
    {
        $limit = $this->config->getRateLimit($context->getUser()->getRole());
        $current = $this->metrics->getCurrentRate($key);

        if ($current >= $limit) {
            $this->logger->warning('Rate limit exceeded', [
                'key' => $key,
                'limit' => $limit,
                'current' => $current,
                'user' => $context->getUser()->getId()
            ]);
            return false;
        }

        $this->metrics->incrementRate($key);
        return true;
    }

    private function checkPermission(array $roles, string $permission): bool
    {
        foreach ($roles as $role) {
            if ($this->permissions->roleHasPermission($role, $permission)) {
                return true;
            }
        }

        foreach ($roles as $role) {
            if ($this->checkInheritedPermissions($role, $permission)) {
                return true;
            }
        }

        return false;
    }

    private function checkInheritedPermissions(Role $role, string $permission): bool
    {
        $inheritedRoles = $this->roles->getInheritedRoles($role);
        
        foreach ($inheritedRoles as $inheritedRole) {
            if ($this->permissions->roleHasPermission($inheritedRole, $permission)) {
                return true;
            }
        }

        return false;
    }

    private function generateCacheKey(SecurityContext $context, string $permission): string
    {
        return hash('sha256', serialize([
            'user_id' => $context->getUser()->getId(),
            'permission' => $permission,
            'version' => $this->config->getVersion()
        ]));
    }

    private function getCachedPermission(string $key): ?bool
    {
        if (!$this->config->isCacheEnabled()) {
            return null;
        }

        return $this->cache->get($key);
    }

    private function cachePermissionResult(string $key, bool $result): void
    {
        if ($this->config->isCacheEnabled()) {
            $this->cache->set(
                $key,
                $result,
                $this->config->getCacheTtl()
            );
        }
    }

    private function logAccessCheck(
        SecurityContext $context,
        string $permission,
        bool $result
    ): void {
        $this->logger->info('Access check', [
            'user_id' => $context->getUser()->getId(),
            'permission' => $permission,
            'granted' => $result,
            'ip' => $context->getIpAddress(),
            'timestamp' => time()
        ]);
    }

    private function recordMetrics(float $startTime, bool $result): void
    {
        $duration = microtime(true) - $startTime;
        
        $this->metrics->record([
            'type' => 'permission_check',
            'duration' => $duration,
            'success' => $result,
            'timestamp' => time()
        ]);
    }

    private function handleAccessFailure(
        \Exception $e,
        SecurityContext $context,
        string $permission
    ): void {
        $this->logger->error('Access check failed', [
            'exception' => $e->getMessage(),
            'user_id' => $context->getUser()->getId(),
            'permission' => $permission,
            'trace' => $e->getTraceAsString()
        ]);

        $this->metrics->incrementFailureCount('access_check');
    }
}
