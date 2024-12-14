<?php

namespace App\Core\Audit;

class AuditAuthorizationManager
{
    private PermissionResolver $permissionResolver;
    private RoleManager $roleManager;
    private PolicyManager $policyManager;
    private AccessCache $cache;
    private LoggerInterface $logger;

    public function __construct(
        PermissionResolver $permissionResolver,
        RoleManager $roleManager,
        PolicyManager $policyManager,
        AccessCache $cache,
        LoggerInterface $logger
    ) {
        $this->permissionResolver = $permissionResolver;
        $this->roleManager = $roleManager;
        $this->policyManager = $policyManager;
        $this->cache = $cache;
        $this->logger = $logger;
    }

    public function authorize(User $user, string $action, AuditResource $resource): AuthorizationResult
    {
        $startTime = microtime(true);

        try {
            // Check cache first
            $cacheKey = $this->generateCacheKey($user, $action, $resource);
            if ($cached = $this->cache->get($cacheKey)) {
                return $cached;
            }

            // Build authorization context
            $context = $this->buildAuthorizationContext($user, $action, $resource);

            // Check permissions
            $permissionCheck = $this->checkPermissions($context);
            if (!$permissionCheck->isAllowed()) {
                return $this->denyAccess($context, $permissionCheck->getReason());
            }

            // Check roles
            $roleCheck = $this->checkRoles($context);
            if (!$roleCheck->isAllowed()) {
                return $this->denyAccess($context, $roleCheck->getReason());
            }

            // Apply policies
            $policyCheck = $this->applyPolicies($context);
            if (!$policyCheck->isAllowed()) {
                return $this->denyAccess($context, $policyCheck->getReason());
            }

            // Grant access
            $result = $this->grantAccess($context);

            // Cache result
            $this->cacheResult($cacheKey, $result);

            // Log access
            $this->logAccess($context, $result, microtime(true) - $startTime);

            return $result;

        } catch (\Exception $e) {
            $this->handleAuthorizationError($e, $context ?? null);
            throw $e;
        }
    }

    public function bulkAuthorize(User $user, array $requests): array
    {
        $results = [];
        $batch = [];

        foreach ($requests as $request) {
            $cacheKey = $this->generateCacheKey(
                $user,
                $request['action'],
                $request['resource']
            );

            if ($cached = $this->cache->get($cacheKey)) {
                $results[$request['id']] = $cached;
            } else {
                $batch[] = $request;
            }
        }

        if (!empty($batch)) {
            $batchResults = $this->processBatchAuthorization($user, $batch);
            $results = array_merge($results, $batchResults);
        }

        return $results;
    }

    protected function checkPermissions(AuthorizationContext $context): PermissionCheckResult
    {
        $requiredPermissions = $this->permissionResolver->resolveRequired(
            $context->getAction(),
            $context->getResource()
        );

        $userPermissions = $this->permissionResolver->resolveUserPermissions(
            $context->getUser()
        );

        foreach ($requiredPermissions as $permission) {
            if (!$this->hasPermission($userPermissions, $permission)) {
                return new PermissionCheckResult(false, "Missing permission: {$permission}");
            }
        }

        return new PermissionCheckResult(true);
    }

    protected function checkRoles(AuthorizationContext $context): RoleCheckResult
    {
        $requiredRoles = $this->roleManager->getRequiredRoles(
            $context->getAction(),
            $context->getResource()
        );

        $userRoles = $this->roleManager->getUserRoles($context->getUser());

        foreach ($requiredRoles as $role) {
            if (!$this->hasRole($userRoles, $role)) {
                return new RoleCheckResult(false, "Missing role: {$role}");
            }
        }

        return new RoleCheckResult(true);
    }

    protected function applyPolicies(AuthorizationContext $context): PolicyCheckResult
    {
        $policies = $this->policyManager->getPolicies(
            $context->getAction(),
            $context->getResource()
        );

        foreach ($policies as $policy) {
            $result = $policy->evaluate($context);
            if (!$result->isAllowed()) {
                return $result;
            }
        }

        return new PolicyCheckResult(true);
    }

    protected function buildAuthorizationContext(
        User $user,
        string $action,
        AuditResource $resource
    ): AuthorizationContext {
        return new AuthorizationContext([
            'user' => $user,
            'action' => $action,
            'resource' => $resource,
            'timestamp' => now(),
            'environment' => $this->getEnvironmentData()
        ]);
    }

    protected function grantAccess(AuthorizationContext $context): AuthorizationResult
    {
        return new AuthorizationResult(
            true,
            $context,
            $this->generateAccessToken($context)
        );
    }

    protected function denyAccess(
        AuthorizationContext $context,
        string $reason
    ): AuthorizationResult {
        $result = new AuthorizationResult(false, $context, null, $reason);

        $this->logDeniedAccess($context, $reason);

        return $result;
    }

    protected function generateAccessToken(AuthorizationContext $context): string
    {
        return hash_hmac('sha256', serialize([
            'user_id' => $context->getUser()->getId(),
            'action' => $context->getAction(),
            'resource_id' => $context->getResource()->getId(),
            'timestamp' => $context->getTimestamp()
        ]), config('audit.auth.token_key'));
    }

    protected function generateCacheKey(
        User $user,
        string $action,
        AuditResource $resource
    ): string {
        return "audit:auth:{$user->getId()}:{$action}:{$resource->getId()}";
    }

    protected function cacheResult(string $key, AuthorizationResult $result): void
    {
        $this->cache->put($key, $result, config('audit.auth.cache_ttl', 3600));
    }

    protected function logAccess(
        AuthorizationContext $context,
        AuthorizationResult $result,
        float $duration
    ): void {
        $this->logger->info('Audit authorization', [
            'user_id' => $context->getUser()->getId(),
            'action' => $context->getAction(),
            'resource_id' => $context->getResource()->getId(),
            'allowed' => $result->isAllowed(),
            'reason' => $result->getReason(),
            'duration' => $duration
        ]);
    }
}
