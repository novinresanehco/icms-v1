<?php

namespace App\Core\Security;

class AccessControlSystem implements AccessControlInterface
{
    protected PermissionRegistry $permissions;
    protected AuditLogger $logger;
    protected CacheManager $cache;
    protected MetricsCollector $metrics;

    public function validateAccess(SecurityContext $context): bool
    {
        DB::beginTransaction();
        try {
            $this->validateContext($context);
            $this->checkPermissions($context);
            $this->validateResourceAccess($context);
            $this->logAccess($context);
            
            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw new AccessDeniedException('Access denied', 0, $e);
        }
    }

    protected function validateContext(SecurityContext $context): void
    {
        if (!$context->getUser() || !$context->getResource()) {
            throw new ValidationException('Invalid security context');
        }

        if (!$this->validateSession($context->getSession())) {
            throw new AuthenticationException('Invalid session');
        }
    }

    protected function checkPermissions(SecurityContext $context): void
    {
        $cacheKey = $this->getPermissionCacheKey($context);
        
        $hasPermission = $this->cache->remember($cacheKey, 300, function() use ($context) {
            return $this->permissions->checkPermission(
                $context->getUser()->getRoles(),
                $context->getResource()->getRequiredPermissions()
            );
        });

        if (!$hasPermission) {
            $this->metrics->increment('access.permission_denied');
            throw new AuthorizationException('Insufficient permissions');
        }
    }

    protected function validateResourceAccess(SecurityContext $context): void
    {
        if (!$context->getResource()->isAccessibleBy($context->getUser())) {
            $this->metrics->increment('access.resource_denied');
            throw new AuthorizationException('Resource access denied');
        }
    }

    protected function validateSession(Session $session): bool
    {
        if ($session->isExpired()) {
            return false;
        }

        return $this->cache->remember(
            "session:{$session->getId()}",
            300,
            fn() => $session->isValid() && !$session->isRevoked()
        );
    }

    protected function getPermissionCacheKey(SecurityContext $context): string
    {
        return sprintf(
            'permissions:%d:%d',
            $context->getUser()->getId(),
            $context->getResource()->getId()
        );
    }

    protected function logAccess(SecurityContext $context): void
    {
        $this->logger->logAccess([
            'user_id' => $context->getUser()->getId(),
            'resource_id' => $context->getResource()->getId(),
            'action' => $context->getAction(),
            'ip_address' => $context->getIpAddress(),
            'timestamp' => now()
        ]);
    }

    protected function handleFailure(\Exception $e, SecurityContext $context): void
    {
        $this->logger->logFailure($e, [
            'user_id' => $context->getUser()->getId(),
            'resource_id' => $context->getResource()->getId(),
            'action' => $context->getAction(),
            'ip_address' => $context->getIpAddress()
        ]);

        if ($e instanceof SecurityException) {
            $this->handleSecurityViolation($e, $context);
        }
    }

    protected function handleSecurityViolation(SecurityException $e, SecurityContext $context): void
    {
        if ($this->isHighRiskViolation($e)) {
            event(new SecurityAlertEvent($e, $context));
            $this->revokeAccess($context);
        }
    }

    protected function isHighRiskViolation(SecurityException $e): bool
    {
        return in_array($e->getCode(), [
            SecurityErrorCodes::PERMISSION_BYPASS_ATTEMPT,
            SecurityErrorCodes::UNAUTHORIZED_RESOURCE_ACCESS,
            SecurityErrorCodes::SUSPICIOUS_ACTIVITY_PATTERN
        ]);
    }

    protected function revokeAccess(SecurityContext $context): void
    {
        $session = $context->getSession();
        $session->revoke();
        
        $this->cache->forget("session:{$session->getId()}");
        event(new SessionRevokedEvent($session));
    }
}
