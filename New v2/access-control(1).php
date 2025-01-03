<?php

namespace App\Core\Security;

class AccessControl implements AccessControlInterface
{
    private RoleManager $roles;
    private PermissionManager $permissions;
    private AuditLogger $audit;
    private MetricsCollector $metrics;
    private array $config;

    public function __construct(
        RoleManager $roles,
        PermissionManager $permissions,
        AuditLogger $audit,
        MetricsCollector $metrics,
        array $config
    ) {
        $this->roles = $roles;
        $this->permissions = $permissions;
        $this->audit = $audit;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function validateAccess(SecurityContext $context): bool
    {
        try {
            $this->validateToken($context->getToken());
            $this->validateRoles($context);
            $this->validatePermissions($context);
            $this->validateRestrictions($context);
            
            $this->audit->logAccessSuccess($context);
            return true;
            
        } catch (AccessException $e) {
            $this->handleAccessFailure($e, $context);
            return false;
        }
    }

    public function validateOperation(Operation $operation, SecurityContext $context): bool
    {
        try {
            $this->validateOperationAccess($operation, $context);
            $this->validateOperationRestrictions($operation, $context);
            $this->enforceOperationLimits($operation, $context);
            
            $this->audit->logOperationAccess($operation, $context);
            return true;
            
        } catch (AccessException $e) {
            $this->handleOperationFailure($e, $operation, $context);
            return false;
        }
    }

    public function validateResource(Resource $resource, SecurityContext $context): bool
    {
        try {
            $this->validateResourceAccess($resource, $context);
            $this->validateResourceRestrictions($resource, $context);
            $this->enforceResourceLimits($resource, $context);
            
            $this->audit->logResourceAccess($resource, $context);
            return true;
            
        } catch (AccessException $e) {
            $this->handleResourceFailure($e, $resource, $context);
            return false;
        }
    }

    protected function validateToken(Token $token): void
    {
        if ($token->isExpired()) {
            throw new TokenExpiredException();
        }

        if (!$this->isTokenValid($token)) {
            throw new InvalidTokenException();
        }
    }

    protected function validateRoles(SecurityContext $context): void
    {
        $requiredRoles = $this->getRequiredRoles($context);
        
        foreach ($requiredRoles as $role) {
            if (!$this->roles->hasRole($context->getUserId(), $role)) {
                throw new RoleRequiredException("Missing required role: {$role}");
            }
        }
    }

    protected function validatePermissions(SecurityContext $context): void
    {
        $requiredPermissions = $this->getRequiredPermissions($context);
        
        foreach ($requiredPermissions as $permission) {
            if (!$this->permissions->hasPermission($context->getUserId(), $permission)) {
                throw new PermissionDeniedException("Missing required permission: {$permission}");
            }
        }
    }

    protected function validateRestrictions(SecurityContext $context): void
    {
        $restrictions = $this->getContextRestrictions($context);
        
        foreach ($restrictions as $restriction) {
            if (!$this->validateRestriction($restriction, $context)) {
                throw new RestrictionViolationException("Restriction violation: {$restriction->getName()}");
            }
        }
    }

    protected function validateOperationAccess(Operation $operation, SecurityContext $context): void
    {
        $required = $this->getOperationRequirements($operation);
        
        if (!empty($required['roles'])) {
            $this->validateOperationRoles($required['roles'], $context);
        }
        
        if (!empty($required['permissions'])) {
            $this->validateOperationPermissions($required['permissions'], $context);
        }
    }

    protected function validateOperationRestrictions(Operation $operation, SecurityContext $context): void
    {
        $restrictions = $this->getOperationRestrictions($operation);
        
        foreach ($restrictions as $restriction) {
            if (!$this->validateOperationRestriction($restriction, $operation, $context)) {
                throw new OperationRestrictionException("Operation restriction violation: {$restriction->getName()}");
            }
        }
    }

    protected function enforceOperationLimits(Operation $operation, SecurityContext $context): void
    {
        $limits = $this->getOperationLimits($operation);
        
        foreach ($limits as $limit) {
            if (!$this->checkOperationLimit($limit, $operation, $context)) {
                throw new OperationLimitException("Operation limit exceeded: {$limit->getName()}");
            }
        }
    }

    protected function validateResourceAccess(Resource $resource, SecurityContext $context): void
    {
        $required = $this->getResourceRequirements($resource);
        
        if (!empty($required['roles'])) {
            $this->validateResourceRoles($required['roles'], $context);
        }
        
        if (!empty($required['permissions'])) {
            $this->validateResourcePermissions($required['permissions'], $context);
        }
    }

    protected function validateResourceRestrictions(Resource $resource, SecurityContext $context): void
    {
        $restrictions = $this->getResourceRestr