<?php

namespace App\Core\Security;

use App\Core\Interfaces\SecurityManagerInterface;
use Illuminate\Support\Facades\DB;
use App\Core\Services\{
    ValidationService,
    EncryptionService,
    AuditLogger,
    AccessControl
};

class CoreSecurityManager implements SecurityManagerInterface 
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
     * Execute critical operation with comprehensive protection
     *
     * @throws SecurityException
     */
    public function executeCriticalOperation(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
        // Start transaction
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($operation, $context);
            
            // Execute with monitoring
            $result = $this->executeWithProtection($operation, $context);
            
            // Verify result
            $this->verifyResult($result);
            
            // Commit and log
            DB::commit();
            $this->auditLogger->logSuccess($operation, $context, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            // Rollback and handle failure
            DB::rollBack();
            $this->handleFailure($operation, $context, $e);
            throw new SecurityException(
                'Operation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }
    
    /**
     * Validates operation before execution
     */
    protected function validateOperation(
        CriticalOperation $operation,
        SecurityContext $context
    ): void {
        // Validate input
        $this->validator->validateInput(
            $operation->getData(),
            $operation->getRules()
        );

        // Check permissions
        if (!$this->accessControl->hasPermission($context, $operation->getRequiredPermissions())) {
            throw new UnauthorizedException();
        }

        // Verify integrity
        if (!$this->encryption->verifyIntegrity($operation->getData())) {
            throw new IntegrityException();
        }
    }

    /**
     * Executes operation with monitoring
     */
    protected function executeWithProtection(
        CriticalOperation $operation,
        SecurityContext $context
    ): OperationResult {
        $monitor = new OperationMonitor($operation, $context);
        
        try {
            $result = $monitor->execute(function() use ($operation) {
                return $operation->execute();
            });

            if (!$result->isValid()) {
                throw new OperationException('Invalid result');
            }

            return $result;
            
        } catch (\Exception $e) {
            $monitor->recordFailure($e);
            throw $e;
        }
    }

    /**
     * Verifies operation result
     */
    protected function verifyResult(OperationResult $result): void {
        if (!$this->validator->verifyResult($result)) {
            throw new ValidationException('Result validation failed');
        }
    }

    /**
     * Handles operation failure
     */
    protected function handleFailure(
        CriticalOperation $operation,
        SecurityContext $context,
        \Exception $e
    ): void {
        // Log detailed failure
        $this->auditLogger->logFailure($operation, $context, $e);
        
        // Notify relevant parties
        $this->notifyFailure($operation, $context, $e);
        
        // Execute recovery if needed
        $this->executeFailureRecovery($operation, $context, $e);
    }
}
