namespace App\Core\Security;

class CoreSecurityManager
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditor;
    private AccessControl $access;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption, 
        AuditLogger $auditor,
        AccessControl $access
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditor = $auditor;
        $this->access = $access;
    }

    public function executeCriticalOperation(CriticalOperation $operation, SecurityContext $context): Result 
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($operation, $context);
            
            // Execute with monitoring
            $result = $this->executeWithProtection($operation, $context);
            
            // Verify result
            $this->verifyResult($result);
            
            DB::commit();
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw $e;
        }
    }

    private function validateOperation(CriticalOperation $operation, SecurityContext $context): void
    {
        // Input validation
        $this->validator->validate($operation->getData());

        // Access control
        if (!$this->access->hasPermission($context, $operation->getRequiredPermissions())) {
            throw new UnauthorizedException();
        }

        // Rate limiting
        if (!$this->access->checkRateLimit($context)) {
            throw new RateLimitException();
        }
    }

    private function executeWithProtection(CriticalOperation $operation, SecurityContext $context): Result
    {
        $startTime = microtime(true);
        
        try {
            $result = $operation->execute();
            
            // Log successful execution
            $this->auditor->logSuccess($context, [
                'operation' => get_class($operation),
                'execution_time' => microtime(true) - $startTime,
                'result' => $result
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->auditor->logFailure($context, $e);
            throw $e;
        }
    }

    private function verifyResult(Result $result): void 
    {
        if (!$this->validator->verifyResultIntegrity($result)) {
            throw new IntegrityException('Result verification failed');
        }
    }

    private function handleFailure(\Exception $e, SecurityContext $context): void
    {
        $this->auditor->logCriticalFailure($e, $context);
        // Additional failure handling like notifications can be added here
    }
}
