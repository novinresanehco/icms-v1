<?php

namespace App\Core\Security;

class SecurityService
{
    protected ValidationService $validator;
    protected EncryptionService $encryption;
    protected AuditLogger $auditLogger;
    
    public function validateOperation(CriticalOperation $operation): bool 
    {
        try {
            DB::beginTransaction();

            // Pre-operation validation
            $this->validator->validateInput($operation->getData());
            $this->verifyAccess($operation->getContext());
            $this->checkIntegrity($operation->getData());

            // Execute with monitoring
            $result = $operation->execute();
            
            // Verify result
            $this->validator->validateResult($result);
            
            DB::commit();
            $this->auditLogger->logSuccess($operation);
            
            return true;

        } catch(\Exception $e) {
            DB::rollBack();
            $this->auditLogger->logFailure($e, $operation);
            throw new SecurityException('Operation validation failed', 0, $e);
        }
    }

    protected function verifyAccess(SecurityContext $context): void 
    {
        if (!$this->validateSession($context->getSession())) {
            throw new SecurityException('Invalid session');
        }

        if (!$this->checkPermissions($context->getUser(), $context->getResource())) {
            throw new SecurityException('Insufficient permissions');
        }
    }

    protected function checkIntegrity(array $data): void 
    {
        if (!$this->encryption->verifyIntegrity($data)) {
            throw new SecurityException('Data integrity check failed');
        }
    }

    protected function validateSession(Session $session): bool
    {
        return !$session->isExpired() && 
               $this->encryption->verifyToken($session->getToken());
    }

    protected function checkPermissions(User $user, Resource $resource): bool
    {
        return $user->hasPermission($resource->getRequiredPermission());
    }
}
