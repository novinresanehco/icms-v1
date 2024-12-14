<?php
namespace App\Core\Security;

class CoreSecurityManager implements SecurityManagerInterface
{
    private AuthenticationService $auth;
    private AuthorizationService $authz;
    private AuditService $audit;
    private ValidationService $validator;
    private MonitoringService $monitor;

    public function __construct(
        AuthenticationService $auth,
        AuthorizationService $authz,
        AuditService $audit,
        ValidationService $validator,
        MonitoringService $monitor
    ) {
        $this->auth = $auth;
        $this->authz = $authz;
        $this->audit = $audit;
        $this->validator = $validator;
        $this->monitor = $monitor;
    }

    public function executeCriticalOperation(callable $operation, SecurityContext $context): mixed
    {
        // Pre-operation validation
        $this->validateOperation($context);
        
        // Start transaction and monitoring
        DB::beginTransaction();
        $operationId = $this->monitor->startOperation($context);
        
        try {
            // Execute with full monitoring
            $result = $operation();

            // Verify result integrity
            $this->validator->validateResult($result);
            
            // Log success and commit
            $this->audit->logSuccess($context, $result);
            DB::commit();
            
            return $result;

        } catch (\Exception $e) {
            // Handle failure with full audit trail
            DB::rollBack();
            $this->handleFailure($e, $context, $operationId);
            throw $e;
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    private function validateOperation(SecurityContext $context): void 
    {
        // Multi-layer validation
        $this->auth->validateAuthentication($context);
        $this->authz->validateAuthorization($context);
        $this->validator->validateInput($context);
        $this->monitor->checkResourceAvailability();
    }

    private function handleFailure(\Exception $e, SecurityContext $context, string $operationId): void
    {
        // Comprehensive failure handling
        $this->audit->logFailure($e, $context, $operationId);
        $this->monitor->recordFailure($operationId);
        
        // Trigger alerts if needed
        if ($e instanceof SecurityException) {
            $this->monitor->triggerSecurityAlert($e);
        }
    }
}
