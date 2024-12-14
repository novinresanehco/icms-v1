<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use App\Core\Interfaces\SecurityManagerInterface;
use App\Core\Services\{
    AuthenticationService,
    AuthorizationService, 
    ValidationService,
    AuditService
};
use App\Core\Models\Operation;
use App\Core\Exceptions\{
    ValidationException,
    SecurityException,
    AccessDeniedException
};

class SecurityManager implements SecurityManagerInterface 
{
    protected AuthenticationService $auth;
    protected AuthorizationService $authz;
    protected ValidationService $validator;
    protected AuditService $audit;

    public function __construct(
        AuthenticationService $auth,
        AuthorizationService $authz,
        ValidationService $validator,
        AuditService $audit
    ) {
        $this->auth = $auth;
        $this->authz = $authz;
        $this->validator = $validator;
        $this->audit = $audit;
    }

    public function validateCriticalOperation(Operation $operation): Result
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateRequest($operation);
            $this->checkPermissions($operation);
            
            // Execute operation
            $result = $this->executeOperation($operation);
            
            // Post-execution tasks
            $this->validateResult($result);
            $this->audit->logSuccess($operation);
            
            DB::commit();
            return $result;
            
        } catch (ValidationException $e) {
            DB::rollBack();
            $this->audit->logValidationFailure($e, $operation);
            throw $e;
            
        } catch (SecurityException $e) {
            DB::rollBack();
            $this->audit->logSecurityFailure($e, $operation);
            throw $e;
            
        } catch (Exception $e) {
            DB::rollBack();
            $this->audit->logSystemFailure($e, $operation);
            throw new SecurityException(
                'Critical operation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    protected function validateRequest(Operation $operation): void
    {
        if (!$this->validator->validate($operation)) {
            throw new ValidationException('Invalid operation request');
        }
    }

    protected function checkPermissions(Operation $operation): void 
    {
        if (!$this->auth->validate($operation->getUser()) || 
            !$this->authz->hasPermission($operation->getUser(), $operation->getRequiredPermission())) {
            throw new AccessDeniedException();
        }
    }

    protected function executeOperation(Operation $operation): Result
    {
        return $operation->execute();
    }

    protected function validateResult(Result $result): void
    {
        if (!$result->isValid()) {
            throw new ValidationException('Operation result validation failed');
        }
    }
}
