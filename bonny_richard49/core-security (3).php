<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use App\Core\Interfaces\SecurityManagerInterface;
use App\Core\Services\{ValidationService, AuditLogger};

class SecurityManager implements SecurityManagerInterface
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        AccessControl $accessControl
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption; 
        $this->auditLogger = $auditLogger;
        $this->accessControl = $accessControl;
    }

    /**
     * Validates and executes a critical operation with comprehensive protection
     *
     * @throws SecurityException
     * @throws ValidationException
     */
    public function executeCriticalOperation(CriticalOperation $operation): OperationResult 
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($operation);
            
            // Execute with monitoring
            $result = $this->executeWithMonitoring($operation);
            
            // Verify result integrity
            $this->verifyResult($result);
            
            DB::commit();
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $operation);
            throw new SecurityException(
                'Critical operation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    private function validateOperation(CriticalOperation $operation): void
    {
        // Validate input data
        $this->validator->validateInput(
            $operation->getData(),
            $operation->getValidationRules()
        );

        // Check permissions
        if (!$this->accessControl->hasPermission($operation->getRequiredPermissions())) {
            throw new UnauthorizedException();
        }

        // Additional security checks
        if (!$this->encryption->verifyIntegrity($operation->getData())) {
            throw new IntegrityException();
        }
    }

    private function executeWithMonitoring(CriticalOperation $operation): OperationResult
    {
        $startTime = microtime(true);
        
        try {
            $result = $operation->execute();
            
            $this->auditLogger->logOperation(
                $operation,
                microtime(true) - $startTime,
                'success'
            );

            return $result;
            
        } catch (\Exception $e) {
            $this->auditLogger->logOperation(
                $operation,
                microtime(true) - $startTime,
                'failure',
                $e
            );
            throw $e;
        }
    }

    private function verifyResult(OperationResult $result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Operation result validation failed');
        }
    }

    private function handleFailure(\Exception $e, CriticalOperation $operation): void
    {
        $this->auditLogger->logFailure(
            $operation,
            $e,
            ['stack_trace' => $e->getTraceAsString()]
        );
    }
}
