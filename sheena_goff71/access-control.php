<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\Cache;
use App\Core\Contracts\AccessControlInterface;
use App\Core\Exceptions\AccessDeniedException;

class AccessControl implements AccessControlInterface
{
    private PermissionRegistry $permissions;
    private RoleManager $roles;
    private AuditLogger $auditLogger;
    private MetricsCollector $metrics;
    private SecurityConfig $config;

    public function __construct(
        PermissionRegistry $permissions,
        RoleManager $roles,
        AuditLogger $auditLogger,
        MetricsCollector $metrics,
        SecurityConfig $config
    ) {
        $this->permissions = $permissions;
        $this->roles = $roles;
        $this->auditLogger = $auditLogger;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function validateAccess(SecurityContext $context): AccessValidationResult
    {
        $startTime = microtime(true);
        DB::beginTransaction();

        try {
            $this->validateAuthentication($context);
            $this->validateAuthorization($context);
            $this->validateResourceAccess($context);
            $this->enforceSecurityPolicies($context);
            
            DB::commit();
            $this->logSuccessfulAccess($context);
            
            return new AccessValidationResult(true);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAccessFailure($e, $context);
            throw new AccessDeniedException('Access validation failed', 0, $e);
        } finally {
            $this->recordMetrics(__FUNCTION__, microtime(true) - $startTime);
        }
    }

    public function checkPermission(User $user, string $permission): bool
    {
        $cacheKey = "permission:{$user->id}:{$permission}";
        
        return Cache::remember($cacheKey, 300, function() use ($user, $permission) {
            $roles = $this->roles->getUserRoles($user);
            
            foreach ($roles as $role) {
                if ($this->roles->hasPermission($role, $permission)) {
                    return true;
                }
            }
            
            return false;
        });
    }

    public function validateResourceAccess(User $user, Resource $resource): bool
    {
        $required = $resource->getRequiredPermissions();
        $userPermissions = $this->permissions->getUserPermissions($user);
        
        foreach ($required as $permission) {
            if (!in_array($permission, $userPermissions)) {
                $this->auditLogger->logUnauthorizedAccess($user, $resource);
                return false;
            }
        }
        
        return true;
    }

    private function validateAuthentication(SecurityContext $context): void
    {
        $user = $context->getUser();
        
        if (!$user || !$this->validateUserSession($user)) {
            throw new AuthenticationException('Invalid user session');
        }

        if ($this->isUserBlocked($user)) {
            throw new UserBlockedException('User is blocked');
        }

        if ($this->requiresMFA($user) && !$this->validateMFA($user)) {
            throw new MFARequiredException('MFA required');
        }
    }

    private function validateAuthorization(SecurityContext $context): void
    {
        $user = $context->getUser();
        $resource = $context->getResource();
        $action = $context->getAction();

        if (!$this->checkPermission($user, "{$resource}:{$action}")) {
            $this->auditLogger->logUnauthorizedAccess($user, $resource, $action);
            throw new AuthorizationException('Insufficient permissions');
        }
    }

    private function enforceSecurityPolicies(SecurityContext $context): void
    {
        // Rate limiting
        $this->enforceRateLimit($context);
        
        // IP validation
        if (!$this->validateIpAccess($context->getIpAddress())) {
            throw new SecurityPolicyException('IP access denied');
        }
        
        // Time-based access
        if (!$this->validateTimeBasedAccess($context)) {
            throw new SecurityPolicyException('Access not allowed at this time');
        }
        
        // Location-based access
        if ($this->config->get('location_check_enabled')) {
            $this->validateLocationAccess($context);
        }
    }

    private function validateUserSession(User $user): bool
    {
        $session = $user->getActiveSession();
        
        return $session && 
               !$session->isExpired() && 
               $this->validateSessionFingerprint($session);
    }

    private function isUserBlocked(User $user): bool
    {
        $key = "user_blocked:{$user->id}";
        
        return Cache::remember($key, 60, function() use ($user) {
            return $this->permissions->isUserBlocked($user);
        });
    }

    private function requiresMFA(User $user): bool
    {
        return $user->hasMFAEnabled() || 
               $this->config->get('force_mfa_roles', [])[$user->role] ?? false;
    }

    private function validateMFA(User $user): bool
    {
        return $user->getCurrentMFASession()?->isValid() ?? false;
    }

    private function enforceRateLimit(SecurityContext $context): void
    {
        $key = "rate_limit:{$context->getUser()->id}:{$context->getAction()}";
        $limit = $this->config->get('rate_limits')[$context->getAction()] ?? 60;
        
        $current = Cache::increment($key);
        
        if ($current === 1) {
            Cache::expire($key, 60);
        }
        
        if ($current > $limit) {
            throw new RateLimitException('Rate limit exceeded');
        }
    }

    private function validateIpAccess(string $ip): bool
    {
        if (in_array($ip, $this->config->get('blocked_ips', []))) {
            return false;
        }
        
        return true;
    }

    private function validateTimeBasedAccess(SecurityContext $context): bool
    {
        $restrictions = $this->config->get('time_restrictions', []);
        $userRole = $context->getUser()->role;
        
        if (!isset($restrictions[$userRole])) {
            return true;
        }
        
        $currentTime = now();
        $allowed = $restrictions[$userRole];
        
        return $currentTime->between($allowed['start'], $allowed['end']);
    }

    private function handleAccessFailure(\Exception $e, SecurityContext $context): void
    {
        $this->auditLogger->logAccessFailure($e, $context);
        $this->metrics->incrementFailureCount('access');
        
        if ($e instanceof SecurityException) {
            $this->handleSecurityViolation($context);
        }
    }

    private function recordMetrics(string $operation, float $duration): void
    {
        $this->metrics->record([
            'type' => 'access_control',
            'operation' => $operation,
            'duration' => $duration,
            'memory' => memory_get_peak_usage(true)
        ]);
    }
}
