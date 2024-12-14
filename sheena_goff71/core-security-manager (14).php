<?php

namespace App\Core\Security;

use App\Core\Interfaces\SecurityManagerInterface;
use App\Core\Security\Services\{ValidationService, EncryptionService, AuditService};
use App\Core\Security\Models\{SecurityContext, ValidationResult};
use App\Core\Exceptions\{ValidationException, SecurityException};
use Illuminate\Support\Facades\DB;

class SecurityManager implements SecurityManagerInterface
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditService $audit;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditService $audit
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->audit = $audit;
    }

    public function validateOperation(SecurityContext $context): ValidationResult 
    {
        DB::beginTransaction();
        
        try {
            // Pre-validation checks
            $this->validateRequest($context);
            $this->checkPermissions($context);
            $this->verifyIntegrity($context);
            
            // Log successful validation
            $this->audit->logValidation($context);
            
            DB::commit();
            return new ValidationResult(true);
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleValidationFailure($e, $context);
            throw new SecurityException('Operation validation failed', 0, $e);
        }
    }

    public function executeSecureOperation(SecurityContext $context, callable $operation)
    {
        $this->validateOperation($context);
        
        DB::beginTransaction();
        try {
            $startTime = microtime(true);
            
            $result = $operation();
            
            $this->audit->logOperation($context, [
                'duration' => microtime(true) - $startTime,
                'result' => $result
            ]);
            
            DB::commit();
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleOperationFailure($e, $context);
            throw new SecurityException('Secure operation failed', 0, $e);
        }
    }

    private function validateRequest(SecurityContext $context): void 
    {
        $validationResult = $this->validator->validateRequest($context->getRequest());
        if (!$validationResult->isValid()) {
            throw new ValidationException($validationResult->getErrors());
        }
    }

    private function checkPermissions(SecurityContext $context): void
    {
        if (!$this->validator->checkPermissions($context)) {
            throw new SecurityException('Insufficient permissions');
        }
    }

    private function verifyIntegrity(SecurityContext $context): void 
    {
        if (!$this->encryption->verifyIntegrity($context->getData())) {
            throw new SecurityException('Data integrity check failed');
        }
    }

    private function handleValidationFailure(\Throwable $e, SecurityContext $context): void 
    {
        $this->audit->logFailure('validation_failure', $e, $context);
    }

    private function handleOperationFailure(\Throwable $e, SecurityContext $context): void 
    {
        $this->audit->logFailure('operation_failure', $e, $context);
    }
}
