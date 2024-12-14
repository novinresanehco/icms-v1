namespace App\Core\Security;

class CoreSecurityManager
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
     * Executes a critical operation with comprehensive security controls
     */
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
            $this->auditLogger->logSuccess($operation);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $operation);
            throw new SecurityException('Critical operation failed', 0, $e);
        }
    }

    private function validateOperation(CriticalOperation $operation): void
    {
        // Validate input data
        if (!$this->validator->validate($operation->getData())) {
            throw new ValidationException('Invalid operation data');
        }

        // Check permissions
        if (!$this->accessControl->hasPermission($operation->getRequiredPermission())) {
            throw new AccessDeniedException('Insufficient permissions');
        }

        // Verify request integrity
        if (!$this->verifyIntegrity($operation)) {
            throw new IntegrityException('Operation integrity check failed');
        }
    }

    private function executeWithProtection(CriticalOperation $operation): OperationResult
    {
        $monitorId = $this->startMonitoring($operation);
        
        try {
            $result = $operation->execute();
            
            if (!$result->isValid()) {
                throw new OperationException('Operation produced invalid result');
            }
            
            return $result;
        } finally {
            $this->stopMonitoring($monitorId);
        }
    }

    private function verifyResult(OperationResult $result): void
    {
        if (!$this->validator->verifyResultIntegrity($result)) {
            throw new IntegrityException('Result integrity verification failed');
        }
    }

    private function handleFailure(\Exception $e, CriticalOperation $operation): void
    {
        $this->auditLogger->logFailure($e, [
            'operation' => $operation->getId(),
            'type' => get_class($operation),
            'data' => $operation->getData(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function startMonitoring(CriticalOperation $operation): string
    {
        return $this->auditLogger->startOperation($operation);
    }

    private function stopMonitoring(string $monitorId): void
    {
        $this->auditLogger->endOperation($monitorId);
    }

    private function verifyIntegrity(CriticalOperation $operation): bool
    {
        return $this->encryption->verifyHash(
            $operation->getData(),
            $operation->getHash()
        );
    }
}
