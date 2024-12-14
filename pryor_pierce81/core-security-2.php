namespace App\Core\Security;

/**
 * Core security manager for critical CMS operations with comprehensive protection
 */
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
     * Executes a critical operation with full protection and auditing
     */
    public function executeCriticalOperation(CriticalOperation $operation): OperationResult 
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($operation);
            
            // Execute with monitoring
            $result = $this->executeWithProtection($operation);
            
            // Verify result 
            $this->verifyResult($result);
            
            DB::commit();
            $this->auditLogger->logSuccess($operation);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($operation, $e);
            throw $e;
        }
    }

    private function validateOperation(CriticalOperation $operation): void 
    {
        // Validate input
        $this->validator->validateInput($operation->getData());
        
        // Check permissions
        if (!$this->accessControl->hasPermission($operation->getRequired())) {
            throw new UnauthorizedException();
        }
        
        // Verify integrity
        if (!$this->encryption->verifyIntegrity($operation->getData())) {
            throw new IntegrityException();
        }
    }

    private function executeWithProtection(CriticalOperation $operation): OperationResult
    {
        // Execute with comprehensive monitoring
        $monitor = new OperationMonitor($operation);
        
        try {
            $result = $monitor->execute(function() use ($operation) {
                return $operation->execute();
            });
            
            // Validate result
            if (!$result->isValid()) {
                throw new OperationException();
            }
            
            return $result;
            
        } catch (\Exception $e) {
            $monitor->recordFailure($e);
            throw $e;
        }
    }

    private function verifyResult(OperationResult $result): void
    {
        // Verify data integrity
        if (!$this->validator->verifyIntegrity($result)) {
            throw new IntegrityException(); 
        }
        
        // Verify business rules
        if (!$this->validator->verifyBusinessRules($result)) {
            throw new BusinessRuleException();
        }
    }

    private function handleFailure(CriticalOperation $operation, \Exception $e): void
    {
        // Log failure with full context
        $this->auditLogger->logFailure($operation, $e, [
            'stack_trace' => $e->getTraceAsString(),
            'input_data' => $operation->getData(),
            'system_state' => $this->captureSystemState()
        ]);

        // Execute failure recovery if needed
        $this->executeFailureRecovery($operation);
    }
}
