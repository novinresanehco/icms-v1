<?php
namespace App\Core\Security;

use App\Core\Interfaces\AccessControlInterface;
use App\Core\Security\{RoleRegistry, PermissionManager, AuthenticationService};
use App\Core\Exceptions\{AccessDeniedException, AuthenticationException};

class AccessControl implements AccessControlInterface
{
    private AuthenticationService $auth;
    private PermissionManager $permissions;
    private RoleRegistry $roles;
    private AuditLogger $audit;
    private RateLimiter $limiter;

    public function validateAuthentication(SecurityContext $context): bool
    {
        if (!$this->auth->verifyAuthentication($context)) {
            $this->audit->logAuthFailure($context);
            throw new AuthenticationException('Invalid authentication');
        }

        if (!$this->auth->validateSession($context)) {
            throw new AuthenticationException('Invalid session');
        }

        return true;
    }

    public function checkAuthorization(SecurityContext $context): bool 
    {
        $user = $context->getUser();
        $resource = $context->getResource();
        $operation = $context->getOperation();

        if (!$this->roles->hasPermission($user, $resource, $operation)) {
            $this->audit->logUnauthorizedAccess($context);
            throw new AccessDeniedException('Access denied');
        }

        if (!$this->permissions->validateResourceAccess($user, $resource)) {
            throw new AccessDeniedException('Resource access denied');
        }

        return true;
    }

    public function checkRateLimit(SecurityContext $context): bool
    {
        return $this->limiter->checkLimit(
            $context->getUser(),
            $context->getOperation()
        );
    }

    public function verifyResourceAccess(User $user, Resource $resource): bool
    {
        if (!$this->permissions->canAccess($user, $resource)) {
            $this->audit->logResourceAccessDenied($user, $resource);
            return false;
        }

        return true;
    }

    public function validatePermissions(array $requiredPermissions, SecurityContext $context): bool
    {
        foreach ($requiredPermissions as $permission) {
            if (!$this->permissions->hasPermission($context->getUser(), $permission)) {
                return false;
            }
        }
        return true;
    }

    private function applySecurityRules(User $user, Resource $resource): bool
    {
        $rules = $this->permissions->getSecurityRules($resource);
        
        foreach ($rules as $rule) {
            if (!$rule->validate($user, $resource)) {
                return false;
            }
        }
        
        return true;
    }
}
