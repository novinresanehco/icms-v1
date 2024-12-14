<?php

namespace App\Core\Security;

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

    public function executeCriticalOperation(CriticalOperation $operation): OperationResult
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($operation);
            
            // Execute with monitoring
            $result = $this->executeWithProtection($operation);
            
            // Verify result integrity
            $this->verifyResult($result);
            
            DB::commit();
            $this->auditLogger->logSuccess($operation, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($operation, $e);
            throw new SecurityException('Critical operation failed', 0, $e);
        }
    }

    private function validateOperation(CriticalOperation $operation): void
    {
        // Validate input data
        $this->validator->validateInput($operation->getData());

        // Check permissions
        if (!$this->accessControl->hasPermission($operation->getRequiredPermission())) {
            throw new UnauthorizedException();
        }

        // Verify rate limits 
        if (!$this->accessControl->checkRateLimit($operation->getRateLimitKey())) {
            throw new RateLimitException();
        }
    }

    private function executeWithProtection(CriticalOperation $operation): OperationResult
    {
        $monitor = new OperationMonitor($operation);
        
        try {
            $result = $monitor->execute(function() use ($operation) {
                return $operation->execute();
            });

            if (!$result->isValid()) {
                throw new OperationException('Invalid operation result');
            }

            return $result;
            
        } catch (\Exception $e) {
            $monitor->recordFailure($e);
            throw $e;
        }
    }

    private function verifyResult(OperationResult $result): void
    {
        if (!$this->validator->verifyIntegrity($result)) {
            throw new IntegrityException();
        }
    }

    private function handleFailure(CriticalOperation $operation, \Exception $e): void
    {
        $this->auditLogger->logFailure($operation, $e);
        
        // Execute recovery procedures if needed
        if ($operation->requiresRecovery()) {
            $this->executeRecovery($operation);
        }
    }
}
