<?php

namespace App\Core\Security\Access;

use App\Core\Security\Models\{SecurityContext, Permission, Role};
use App\Core\Exceptions\{AccessDeniedException, SecurityException};
use Illuminate\Support\Facades\{Cache, DB};

class AccessControlSystem
{
    private RoleRegistry $roles;
    private PermissionManager $permissions;
    private AuditLogger $logger;
    private SecurityConfig $config;
    private MetricsCollector $metrics;

    public function __construct(
        RoleRegistry $roles,
        PermissionManager $permissions,
        AuditLogger $logger,
        SecurityConfig $config,
        MetricsCollector $metrics
    ) {
        $this->roles = $roles;
        $this->permissions = $permissions;
        $this->logger = $logger;
        $this->config = $config;
        $this->metrics = $metrics;
    }

    public function validateAccess(
        SecurityContext $context,
        array $requiredPermissions
    ): void {
        $startTime = microtime(true);

        try {
            $this->validateAuthentication($context);
            $this->validateAuthorization($context, $requiredPermissions);
            $this->validateSecurityContext($context);
            $this->enforceRateLimits($context);
            
            $this->logSuccessfulAccess($context, $requiredPermissions);
            
        } catch (\Exception $e) {
            $this->handleAccessFailure($context, $requiredPermissions, $e);
            throw $e;
        } finally {
            $this->recordMetrics($context, microtime(true) - $startTime);
        }
    }

    private function validateAuthentication(SecurityContext $context): void
    {
        if (!$context->isAuthenticated()) {
            throw new AccessDeniedException('Authentication required');
        }

        if ($this->isSessionExpired($context)) {
            throw new AccessDeniedException('Session expired');
        }

        if ($this->detectSessionAnomaly($context)) {
            throw new SecurityException('Session anomaly detected');
        }
    }

    private function validateAuthorization(
        SecurityContext $context,
        array $requiredPermissions
    ): void {
        $userRoles = $this->roles->getUserRoles($context->getUserId());
        
        foreach ($requiredPermissions as $permission) {
            if (!$this->hasPermissionThroughRoles($userRoles, $permission)) {
                throw new AccessDeniedException(
                    "Missing required permission: {$permission}"
                );
            }
        }

        if (!$this->validateRoleConstraints($userRoles, $context)) {
            throw new SecurityException('Role constraints validation failed');
        }
    }

    private function hasPermissionThroughRoles(array $roles, string $permission): bool
    {
        $permissionKey = "permission:{$permission}";
        
        return Cache::remember(
            $permissionKey,
            $this->config->getCacheDuration(),
            function() use ($roles, $permission) {
                foreach ($roles as $role) {
                    if ($this->permissions->roleHasPermission($role, $permission)) {
                        return true;
                    }
                }
                return false;
            }
        );
    }

    private function validateSecurityContext(SecurityContext $context): void
    {
        if (!$this->validateIpAddress($context->getIpAddress())) {
            throw new SecurityException('Invalid IP address');
        }

        if ($this->detectAnomalousActivity($context)) {
            throw new SecurityException('Anomalous activity detected');
        }

        if (!$this->validateSecurityLevel($context)) {
            throw new SecurityException('Insufficient security level');
        }
    }

    private function enforceRateLimits(SecurityContext $context): void
    {
        $key = $this->getRateLimitKey($context);
        $window = $this->config->getRateLimitWindow();
        
        $attempts = Cache::increment($key);
        
        if ($attempts === 1) {
            Cache::put($key, 1, $window);
        }
        
        if ($attempts > $this->config->getRateLimit()) {
            throw new SecurityException('Rate limit exceeded');
        }
    }

    private function detectSessionAnomaly(SecurityContext $context): bool
    {
        $sessionKey = "session:{$context->getSessionId()}";
        $storedData = Cache::get($sessionKey);
        
        if (!$storedData) {
            return true;
        }

        return $storedData['ip'] !== $context->getIpAddress() ||
               $storedData['user_agent'] !== $context->getUserAgent();
    }

    private function validateRoleConstraints(array $roles, SecurityContext $context): bool
    {
        foreach ($roles as $role) {
            if (!$this->validateTimeConstraints($role, $context)) {
                return false;
            }
            
            if (!$this->validateLocationConstraints($role, $context)) {
                return false;
            }
            
            if (!$this->validateSecurityLevelConstraints($role, $context)) {
                return false;
            }
        }
        
        return true;
    }

    private function handleAccessFailure(
        SecurityContext $context,
        array $permissions,
        \Exception $e
    ): void {
        $this->logger->logAccessFailure($context, $permissions, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'security_context' => $this->getSecurityContextData($context)
        ]);

        $this->metrics->incrementFailureCount(
            'access_control',
            $e instanceof AccessDeniedException ? 'denied' : 'error'
        );

        if ($this->shouldTriggerSecurityAlert($e)) {
            $this->triggerSecurityAlert($context, $e);
        }
    }

    private function recordMetrics(SecurityContext $context, float $duration): void
    {
        $this->metrics->record([
            'access_control_duration' => $duration,
            'user_id' => $context->getUserId(),
            'ip_address' => $context->getIpAddress(),
            'timestamp' => microtime(true)
        ]);
    }

    private function getRateLimitKey(SecurityContext $context): string
    {
        return sprintf(
            'rate_limit:%s:%s',
            $context->getUserId(),
            $context->getIpAddress()
        );
    }
}
