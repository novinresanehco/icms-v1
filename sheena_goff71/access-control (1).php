<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\Cache;
use App\Core\Exceptions\AccessDeniedException;
use App\Core\Interfaces\AccessControlInterface;

class AccessControl implements AccessControlInterface
{
    private RoleManager $roleManager;
    private PermissionRegistry $permissions;
    private AuditLogger $auditLogger;
    private SecurityConfig $config;

    public function checkAccess(SecurityContext $context): bool
    {
        try {
            $this->validateSession($context);
            $this->checkPermissions($context);
            $this->validateResourceAccess($context);
            $this->enforceRateLimits($context);
            
            $this->auditLogger->logAccess($context);
            return true;
            
        } catch (AccessDeniedException $e) {
            $this->auditLogger->logAccessDenied($context, $e);
            throw $e;
        }
    }

    protected function validateSession(SecurityContext $context): void 
    {
        if (!$context->hasValidSession()) {
            throw new AccessDeniedException('Invalid session');
        }

        if ($this->isSessionExpired($context)) {
            throw new AccessDeniedException('Session expired');
        }

        if ($this->detectSessionAnomaly($context)) {
            throw new AccessDeniedException('Session anomaly detected');
        }
    }

    protected function checkPermissions(SecurityContext $context): void
    {
        $user = $context->getUser();
        $resource = $context->getResource();
        $action = $context->getAction();

        if (!$this->roleManager->hasPermission($user->getRole(), $resource, $action)) {
            throw new AccessDeniedException('Insufficient permissions');
        }

        if (!$this->validateContextualPermissions($context)) {
            throw new AccessDeniedException('Contextual permission denied');
        }
    }

    protected function validateResourceAccess(SecurityContext $context): void
    {
        $resource = $context->getResource();
        $restrictions = $this->permissions->getResourceRestrictions($resource);

        foreach ($restrictions as $restriction) {
            if (!$restriction->validate($context)) {
                throw new AccessDeniedException("Resource access restriction: {$restriction->getReason()}");
            }
        }
    }

    protected function enforceRateLimits(SecurityContext $context): void
    {
        $limits = $this->config->getRateLimits($context->getAction());
        
        $key = $this->getRateLimitKey($context);
        $current = (int)Cache::get($key, 0);

        if ($current >= $limits['max_requests']) {
            throw new AccessDeniedException('Rate limit exceeded');
        }

        Cache::put($key, $current + 1, $limits['time_window']);
    }

    protected function isSessionExpired(SecurityContext $context): bool
    {
        $session = $context->getSession();
        $maxAge = $this->config->getSessionMaxAge();
        
        return ($session->getLastActivity() + $maxAge) < time();
    }

    protected function detectSessionAnomaly(SecurityContext $context): bool
    {
        $session = $context->getSession();
        
        if ($this->ipChanged($session, $context)) {
            return true;
        }

        if ($this->userAgentChanged($session, $context)) {
            return true;
        }

        if ($this->detectSuspiciousActivity($session, $context)) {
            return true;
        }

        return false;
    }

    protected function validateContextualPermissions(SecurityContext $context): bool
    {
        $rules = $this->permissions->getContextualRules($context->getResource());
        
        foreach ($rules as $rule) {
            if (!$rule->evaluate($context)) {
                return false;
            }
        }
        
        return true;
    }

    protected function getRateLimitKey(SecurityContext $context): string
    {
        return sprintf(
            'rate_limit:%s:%s:%s',
            $context->getUser()->getId(),
            $context->getAction(),
            $context->getResource()
        );
    }

    protected function ipChanged(Session $session, SecurityContext $context): bool
    {
        return $session->getIpAddress() !== $context->getIpAddress();
    }

    protected function userAgentChanged(Session $session, SecurityContext $context): bool
    {
        return $session->getUserAgent() !== $context->getUserAgent();
    }

    protected function detectSuspiciousActivity(Session $session, SecurityContext $context): bool
    {
        return $this->detectAnomalousPatterns($session, $context) ||
               $this->detectTimingAnomalies($session, $context) ||
               $this->detectLocationAnomalies($session, $context);
    }

    protected function detectAnomalousPatterns(Session $session, SecurityContext $context): bool
    {
        $patterns = $this->permissions->getAnomalyPatterns();
        $history = $session->getActivityHistory();
        
        foreach ($patterns as $pattern) {
            if ($pattern->matches($history, $context)) {
                return true;
            }
        }
        
        return false;
    }

    protected function detectTimingAnomalies(Session $session, SecurityContext $context): bool
    {
        $lastActivity = $session->getLastActivity();
        $currentTime = time();
        
        return ($currentTime - $lastActivity) < $this->config->getMinimumRequestInterval();
    }

    protected function detectLocationAnomalies(Session $session, SecurityContext $context): bool
    {
        if (!$this->config->isLocationCheckEnabled()) {
            return false;
        }

        $lastLocation = $session->getLastLocation();
        $currentLocation = $context->getLocation();
        
        return $this->isImpossibleTravel($lastLocation, $currentLocation, $session->getLastActivity());
    }

    protected function isImpossibleTravel($lastLocation, $currentLocation, $lastActivity): bool
    {
        if (!$lastLocation || !$currentLocation) {
            return false;
        }

        $distance = $this->calculateDistance($lastLocation, $currentLocation);
        $timeElapsed = time() - $lastActivity;
        $maxSpeed = $this->config->getMaxTravelSpeed();

        return ($distance / $timeElapsed) > $maxSpeed;
    }
}
