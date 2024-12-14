<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use App\Core\Interfaces\SecurityManagerInterface;
use App\Core\Services\{ValidationService, EncryptionService, AuditService};
use App\Core\Exceptions\{SecurityException, ValidationException, AuthorizationException};

class CoreSecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditService $audit;
    private array $securityConfig;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditService $audit,
        array $securityConfig
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->audit = $audit;
        $this->securityConfig = $securityConfig;
    }

    public function executeCriticalOperation(callable $operation, array $context): mixed 
    {
        DB::beginTransaction();
        
        try {
            $this->validateOperation($context);
            $this->audit->logOperationStart($context);
            
            $result = $operation();
            
            $this->validateResult($result);
            DB::commit();
            
            $this->audit->logOperationSuccess($context);
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, $context);
            throw $e;
        }
    }

    protected function validateOperation(array $context): void 
    {
        if (!$this->validator->validateContext($context)) {
            throw new ValidationException('Invalid operation context');
        }

        if (!$this->checkAuthorization($context)) {
            throw new AuthorizationException('Operation not authorized');
        }
    }

    protected function validateResult($result): void 
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Operation result validation failed');
        }
    }

    protected function checkAuthorization(array $context): bool 
    {
        return isset($context['user']) && 
               $this->validator->validatePermissions(
                   $context['user'], 
                   $context['required_permissions'] ?? []
               );
    }

    protected function handleOperationFailure(\Throwable $e, array $context): void 
    {
        $this->audit->logOperationFailure($e, $context);
        
        if ($e instanceof SecurityException) {
            $this->handleSecurityBreach($e, $context);
        }
    }

    protected function handleSecurityBreach(\Throwable $e, array $context): void 
    {
        $this->audit->logSecurityBreach($e, $context);
        $this->notifySecurityTeam($e, $context);
    }

    protected function notifySecurityTeam(\Throwable $e, array $context): void 
    {
        // Implementation for security team notification
        // Critical for breach response but not blocking for MVP
    }
}
