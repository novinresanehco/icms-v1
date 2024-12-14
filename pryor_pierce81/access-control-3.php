<?php

namespace App\Core\Security\Access;

class AccessControlService implements AccessControlInterface
{
    private PolicyEnforcer $policyEnforcer;
    private PermissionValidator $permissionValidator;
    private RoleManager $roleManager;
    private AccessLogger $logger;
    private SecurityProtocol $security;
    private EmergencyProtocol $emergency;

    public function __construct(
        PolicyEnforcer $policyEnforcer,
        PermissionValidator $permissionValidator,
        RoleManager $roleManager,
        AccessLogger $logger,
        SecurityProtocol $security,
        EmergencyProtocol $emergency
    ) {
        $this->policyEnforcer = $policyEnforcer;
        $this->permissionValidator = $permissionValidator;
        $this->roleManager = $roleManager;
        $this->logger = $logger;
        $this->security = $security;
        $this->emergency = $emergency;
    }

    public function checkAccess(AccessRequest $request): AccessResult
    {
        $accessId = $this->initializeAccessCheck($request);
        
        try {
            DB::beginTransaction();

            $this->validateRequest($request);
            $this->checkPermissions($request);
            $this->enforcePolicy($request);
            $this->validateRole($request);

            if ($this->emergency->isActive()) {
                $this->handleEmergencyAccess($request);
            }

            $result = new AccessResult([
                'accessId' => $accessId,
                'granted' => true,
                'restrictions' => $this->getAccessRestrictions($request),
                'timestamp' => now()
            ]);

            DB::commit();
            $this->finalizeAccess($result);

            return $result;

        } catch (AccessException $e) {
            DB::rollBack();
            $this->handleAccessFailure($e, $accessId);
            throw new CriticalAccessException($e->getMessage(), $e);
        }
    }

    private function checkPermissions(AccessRequest $request): void
    {
        if (!$this->permissionValidator->validate($request->getPermissions())) {
            throw new PermissionException('Required permissions not met');
        }
    }

    private function enforcePolicy(AccessRequest $request): void
    {
        $violations = $this->policyEnforcer->enforce($request);
        
        if (!empty($violations)) {
            $this->security->handlePolicyViolation($violations);
            throw new PolicyViolationException('Access policy violation detected');
        }
    }

    private function handleEmergencyAccess(AccessRequest $request): void
    {
        if (!$this->emergency->isAccessAllowed($request)) {
            throw new EmergencyAccessException('Access denied during emergency protocol');
        }
        
        $this->logger->logEmergencyAccess($request);
    }

    private function handleAccessFailure(AccessException $e, string $accessId): void
    {
        $this->logger->logFailure($e, $accessId);
        $this->security->handleAccessViolation($e);

        if ($e->isCritical()) {
            $this->emergency->initiateAccessLockdown();
        }
    }
}
