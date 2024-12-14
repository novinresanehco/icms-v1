<?php

namespace App\Core\Security;

use App\Core\Interfaces\SecurityManagerInterface;
use App\Core\Services\{ValidationService, EncryptionService, AuditService};
use App\Core\Exceptions\{SecurityException, ValidationException, AuthorizationException};
use Illuminate\Support\Facades\DB;

class SecurityManager implements SecurityManagerInterface
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

    public function validateOperation(SecurityContext $context): bool 
    {
        DB::beginTransaction();
        
        try {
            // Pre-operation validation
            $this->validateContext($context);
            $this->checkPermissions($context);
            $this->verifyIntegrity($context);
            
            // Log successful validation
            $this->audit->logValidation($context);
            
            DB::commit();
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            // Log failure with full context
            $this->audit->logFailure($e, $context);
            
            throw new SecurityException(
                'Operation validation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    protected function validateContext(SecurityContext $context): void
    {
        if (!$this->validator->validateContext($context)) {
            throw new ValidationException('Invalid security context');
        }
    }

    protected function checkPermissions(SecurityContext $context): void
    {
        if (!$this->validator->checkPermissions($context)) {
            throw new AuthorizationException('Insufficient permissions');
        }
    }

    protected function verifyIntegrity(SecurityContext $context): void
    {
        if (!$this->encryption->verifyIntegrity($context->getData())) {
            throw new SecurityException('Data integrity validation failed');
        }
    }

    public function executeSecureOperation(callable $operation, SecurityContext $context)
    {
        if (!$this->validateOperation($context)) {
            throw new SecurityException('Security validation failed for operation');
        }

        return DB::transaction(function() use ($operation, $context) {
            $result = $operation();
            $this->audit->logOperation($context, $result);
            return $result;
        });
    }
}
