namespace App\Core\Security;

class AccessControl implements AccessControlInterface 
{
    private PermissionRegistry $permissions;
    private CacheManager $cache;
    private AuditLogger $auditLogger;
    private SecurityConfig $config;
    
    public function __construct(
        PermissionRegistry $permissions,
        CacheManager $cache,
        AuditLogger $auditLogger,
        SecurityConfig $config
    ) {
        $this->permissions = $permissions;
        $this->cache = $cache;
        $this->auditLogger = $auditLogger;
        $this->config = $config;
    }

    public function validateAccess(AccessRequest $request): AccessResult
    {
        $startTime = microtime(true);
        
        try {
            // Verify authentication
            $this->verifyAuthentication($request->getUser());
            
            // Check permissions
            $this->checkPermissions($request);
            
            // Verify rate limits
            $this->checkRateLimits($request);
            
            // Validate IP restrictions
            $this->validateIpRestrictions($request);
            
            // Log successful access
            $this->logAccess($request, true, microtime(true) - $startTime);
            
            return new AccessResult(true);
            
        } catch (AccessException $e) {
            $this->logAccess($request, false, microtime(true) - $startTime, $e);
            throw $e;
        }
    }

    private function verifyAuthentication(User $user): void
    {
        if (!$user->isAuthenticated()) {
            throw new AuthenticationException('User not authenticated');
        }

        if ($user->isSessionExpired()) {
            throw new SessionExpiredException('User session expired');
        }

        if ($user->requiresMfa() && !$user->hasMfaVerified()) {
            throw new MfaRequiredException('MFA verification required');
        }
    }

    private function checkPermissions(AccessRequest $request): void
    {
        $user = $request->getUser();
        $resource = $request->getResource();
        $action = $request->getAction();

        $cacheKey = "permissions:{$user->getId()}:{$resource}:{$action}";
        
        $hasPermission = $this->cache->remember($cacheKey, function() use ($user, $resource, $action) {
            return $this->permissions->userHasPermission($user, $resource, $action);
        });

        if (!$hasPermission) {
            throw new PermissionDeniedException(
                "User {$user->getId()} lacks permission for {$action} on {$resource}"
            );
        }
    }

    private function checkRateLimits(AccessRequest $request): void
    {
        $key = $this->getRateLimitKey($request);
        $limit = $this->config->getRateLimit($request->getResource());
        
        if ($this->isRateLimitExceeded($key, $limit)) {
            throw new RateLimitExceededException('Rate limit exceeded');
        }
        
        $this->incrementRateLimit($key);
    }

    private function validateIpRestrictions(AccessRequest $request): void
    {
        $ip = $request->getIpAddress();
        $resource = $request->getResource();
        
        if ($this->isIpBlocked($ip, $resource)) {
            throw new IpRestrictedException("IP {$ip} is blocked for {$resource}");
        }
    }

    private function logAccess(
        AccessRequest $request, 
        bool $granted,
        float $duration,
        ?\Exception $error = null
    ): void {
        $this->auditLogger->logAccess([
            'user_id' => $request->getUser()->getId(),
            'resource' => $request->getResource(),
            'action' => $request->getAction(),
            'ip' => $request->getIpAddress(),
            'granted' => $granted,
            'duration' => $duration,
            'error' => $error ? $error->getMessage() : null,
            'timestamp' => time()
        ]);
    }

    private function getRateLimitKey(AccessRequest $request): string
    {
        return sprintf(
            'rate_limit:%s:%s:%s',
            $request->getUser()->getId(),
            $request->getResource(),
            $request->getIpAddress()
        );
    }

    private function isRateLimitExceeded(string $key, int $limit): bool
    {
        return (int)$this->cache->get($key, 0) >= $limit;
    }

    private function incrementRateLimit(string $key): void
    {
        $this->cache->increment($key);
        $this->cache->expire($key, $this->config->getRateLimitWindow());
    }

    private function isIpBlocked(string $ip, string $resource): bool
    {
        $blockedIps = $this->cache->get('blocked_ips', []);
        $resourceRestrictions = $this->config->getIpRestrictions($resource);
        
        return in_array($ip, $blockedIps) || 
               !$this->ipMatchesRestrictions($ip, $resourceRestrictions);
    }

    private function ipMatchesRestrictions(string $ip, array $restrictions): bool
    {
        if (empty($restrictions)) {
            return true;
        }

        foreach ($restrictions as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    private function ipInRange(string $ip, string $range): bool
    {
        return ip2long($ip) >= ip2long($range[0]) && 
               ip2long($ip) <= ip2long($range[1]);
    }
}
