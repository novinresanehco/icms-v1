<?php

namespace App\Core\Security;

class AuthorizationControlSystem implements AuthorizationInterface 
{
    private PermissionManager $permissions;
    private RoleValidator $roleValidator;
    private AccessLogger $logger;
    private SecurityMonitor $monitor;
    private CacheManager $cache;

    public function __construct(
        PermissionManager $permissions,
        RoleValidator $roleValidator,
        AccessLogger $logger,
        SecurityMonitor $monitor,
        CacheManager $cache
    ) {
        $this->permissions = $permissions;
        $this->roleValidator = $roleValidator;
        $this->logger = $logger;
        $this->monitor = $monitor;
        $this->cache = $cache;
    }

    public function validateAccess(AccessRequest $request): AuthorizationResult 
    {
        $validationId = $this->monitor->startValidation();
        DB::beginTransaction();

        try {
            // Role validation
            $roleValid = $this->roleValidator->validateRole(
                $request->getUser(),
                $request->getRequiredRole()
            );

            if (!$roleValid) {
                throw new AuthorizationException('Invalid role for requested access');
            }

            // Permission check
            $permissionGranted = $this->permissions->checkPermission(
                $request->getUser(),
                $request->getRequiredPermission()
            );

            if (!$permissionGranted) {
                throw new AuthorizationException('Required permission not granted');
            }

            // Resource access verification
            $resourceAccess = $this->validateResourceAccess(
                $request->getUser(),
                $request->getResource()
            );

            if (!$resourceAccess->isGranted()) {
                throw new AuthorizationException('Resource access denied');
            }

            $this->logger->logSuccess($validationId);
            DB::commit();

            return new AuthorizationResult(true);

        } catch (AuthorizationException $e) {
            DB::rollBack();
            $this->handleFailure($validationId, $request, $e);
            throw $e;
        }
    }

    public function validateResourceAccess(User $user, Resource $resource): AccessValidation 
    {
        $cacheKey = $this->generateCacheKey($user, $resource);

        return $this->cache->remember($cacheKey, function() use ($user, $resource) {
            $validation = new AccessValidation();

            // Verify resource permissions
            $validation->setPermissionStatus(
                $this->permissions->validateResourcePermissions($user, $resource)
            );

            // Check role requirements
            $validation->setRoleStatus(
                $this->roleValidator->validateResourceRole($user, $resource)
            );

            // Verify access policies
            $validation->setPolicyStatus(
                $this->validateAccessPolicies($user, $resource)
            );

            return $validation;
        });
    }

    private function validateAccessPolicies(User $user, Resource $resource): bool 
    {
        foreach ($resource->getPolicies() as $policy) {
            if (!$policy->validate($user)) {
                return false;
            }
        }
        return true;
    }

    private function handleFailure(
        string $validationId,
        AccessRequest $request,
        AuthorizationException $e
    ): void {
        $this->logger->logFailure($validationId, [
            'user_id' => $request->getUser()->getId(),
            'resource' => $request->getResource()->getIdentifier(),
            'error' => $e->getMessage(),
            'context' => $this->monitor->getValidationContext($validationId)
        ]);

        $this->monitor->recordSecurityEvent(
            new SecurityEvent(
                type: SecurityEventType::AUTHORIZATION_FAILURE,
                severity: SecurityEventSeverity::HIGH,
                context: [
                    'validation_id' => $validationId,
                    'request' => $request->toArray(),
                    'error' => $e->getMessage()
                ]
            )
        );
    }

    private function generateCacheKey(User $user, Resource $resource): string 
    {
        return hash('sha256', json_encode([
            'user_id' => $user->getId(),
            'resource_id' => $resource->getId(),
            'timestamp' => now()->timestamp
        ]));
    }
}

class PermissionManager 
{
    private PermissionRepository $repository;
    private ValidationEngine $validator;
    private CacheManager $cache;

    public function checkPermission(User $user, Permission $permission): bool 
    {
        $cacheKey = "permission:{$user->getId()}:{$permission->getId()}";

        return $this->cache->remember($cacheKey, function() use ($user, $permission) {
            $userPermissions = $this->repository->getUserPermissions($user);
            return $this->validator->validatePermission($permission, $userPermissions);
        });
    }

    public function validateResourcePermissions(User $user, Resource $resource): bool 
    {
        $requiredPermissions = $resource->getRequiredPermissions();
        
        foreach ($requiredPermissions as $permission) {
            if (!$this->checkPermission($user, $permission)) {
                return false;
            }
        }
        
        return true;
    }
}

class RoleValidator 
{
    private RoleHierarchy $hierarchy;
    private ValidationEngine $validator;

    public function validateRole(User $user, Role $requiredRole): bool 
    {
        $userRole = $user->getRole();
        return $this->hierarchy->hasRequiredLevel($userRole, $requiredRole);
    }

    public function validateResourceRole(User $user, Resource $resource): bool 
    {
        $requiredRole = $resource->getRequiredRole();
        return $this->validateRole($user, $requiredRole);
    }
}

class AccessLogger 
{
    private EventLogger $logger;
    private MetricsCollector $metrics;

    public function logSuccess(string $validationId): void 
    {
        $this->logger->info('authorization_success', [
            'validation_id' => $validationId,
            'timestamp' => now(),
            'metrics' => $this->metrics->getMetrics($validationId)
        ]);
    }

    public function logFailure(string $validationId, array $context): void 
    {
        $this->logger->error('authorization_failure', [
            'validation_id' => $validationId,
            'timestamp' => now(),
            'context' => $context,
            'metrics' => $this->metrics->getMetrics($validationId)
        ]);
    }
}
