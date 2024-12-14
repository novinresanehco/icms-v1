<?php

namespace App\Core\Security\Authorization;

class AuthorizationService implements AuthorizationInterface
{
    private PermissionManager $permissionManager;
    private RoleValidator $roleValidator;
    private AccessControl $accessControl;
    private PolicyEnforcer $policyEnforcer;
    private AuthorizationLogger $logger;
    private SecurityProtocol $security;

    public function __construct(
        PermissionManager $permissionManager,
        RoleValidator $roleValidator,
        AccessControl $accessControl,
        PolicyEnforcer $policyEnforcer,
        AuthorizationLogger $logger,
        SecurityProtocol $security
    ) {
        $this->permissionManager = $permissionManager;
        $this->roleValidator = $roleValidator;
        $this->accessControl = $accessControl;
        $this->policyEnforcer = $policyEnforcer;
        $this->logger = $logger;
        $this->security = $security;
    }

    public function authorize(AuthorizationRequest $request): AuthorizationResult
    {
        $authzId = $this->initializeAuthorization($request);
        
        try {
            DB::beginTransaction();

            $this->validateIdentity($request);
            $this->validateRole($request);
            $this->checkPermissions($request);
            $this->enforcePolicy($request);

            $result = new AuthorizationResult([
                'authzId' => $authzId,
                'status' => AuthorizationStatus::AUTHORIZED,
                'permissions' => $this->grantedPermissions($request),
                'timestamp' => now()
            ]);

            DB::commit();
            $this->finalizeAuthorization($result);

            return $result;

        } catch (AuthorizationException $e) {
            DB::rollBack();
            $this->handleAuthorizationFailure($e, $authzId);
            throw new CriticalAuthorizationException($e->getMessage(), $e);
        }
    }

    private function validateRole(AuthorizationRequest $request): void
    {
        if (!$this->roleValidator->validateRole($request->getRole())) {
            throw new InvalidRoleException('Role validation failed');
        }
    }

    private function checkPermissions(AuthorizationRequest $request): void
    {
        $required = $request->getRequiredPermissions();
        $granted = $this->permissionManager->getPermissions($request->getIdentity());

        if (!$this->permissionManager->hasRequiredPermissions($granted, $required)) {
            throw new InsufficientPermissionsException('Insufficient permissions');
        }
    }

    private function enforcePolicy(AuthorizationRequest $request): void
    {
        $violations = $this->policyEnforcer->enforce($request);
        
        if (!empty($violations)) {
            throw new PolicyViolationException(
                'Policy enforcement failed',
                ['violations' => $violations]
            );
        }
    }

    private function handleAuthorizationFailure(
        AuthorizationException $e,
        string $authzId
    ): void {
        $this->logger->logFailure($e, $authzId);
        $this->security->handleAuthorizationFailure($e);

        if ($e->isCritical()) {
            $this->security->lockdownAccess($authzId);
        }
    }
}
