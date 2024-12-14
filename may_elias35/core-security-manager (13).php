namespace App\Core\Security;

class CoreSecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private SecurityConfig $config;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        SecurityConfig $config
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->config = $config;
    }

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
            $this->logSuccess($operation, $result);
            
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
        $this->validator->validate($operation->getData());

        // Check permissions
        if (!$this->hasPermission($operation->getRequiredPermissions())) {
            throw new UnauthorizedException();
        }

        // Rate limiting
        if (!$this->checkRateLimit($operation->getRateLimitKey())) {
            throw new RateLimitException();
        }
    }

    private function executeWithProtection(CriticalOperation $operation): OperationResult
    {
        // Create monitoring context
        $monitor = new OperationMonitor($operation);
        
        try {
            $result = $monitor->execute(function() use ($operation) {
                return $operation->execute();
            });

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

    private function logSuccess(CriticalOperation $operation, OperationResult $result): void
    {
        $this->auditLogger->logSuccess([
            'operation' => $operation->getName(),
            'result' => $result->getData(),
            'timestamp' => time()
        ]);
    }

    private function handleFailure(CriticalOperation $operation, \Exception $e): void
    {
        $this->auditLogger->logFailure([
            'operation' => $operation->getName(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => time()
        ]);
    }
}
