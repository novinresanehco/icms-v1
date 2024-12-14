namespace App\Core\Security;

class SecurityManager implements SecurityManagerInterface 
{
    private $validator;
    private $encryptor;
    private $auditLogger;
    private $accessControl;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryptor,
        AuditLogger $auditLogger,
        AccessControl $accessControl
    ) {
        $this->validator = $validator;
        $this->encryptor = $encryptor;
        $this->auditLogger = $auditLogger;
        $this->accessControl = $accessControl;
    }

    public function executeCriticalOperation(callable $operation, SecurityContext $context): mixed
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution security checks
            $this->validateOperation($context);
            
            // Execute with monitoring
            $startTime = microtime(true);
            $result = $operation();
            $execTime = microtime(true) - $startTime;
            
            // Verify result integrity
            $this->verifyResult($result);
            
            $this->auditLogger->logSuccess($context, $execTime);
            DB::commit();
            return $result;
            
        } catch (SecurityException $e) {
            DB::rollback();
            $this->auditLogger->logFailure($e, $context);
            throw $e;
        }
    }

    private function validateOperation(SecurityContext $context): void 
    {
        // Input validation
        $this->validator->validateInput($context->getData());

        // Permission check
        if (!$this->accessControl->hasPermission($context)) {
            throw new SecurityException('Permission denied');
        }

        // Rate limiting
        if (!$this->accessControl->checkRateLimit($context)) {
            throw new SecurityException('Rate limit exceeded');
        }
    }

    private function verifyResult($result): void 
    {
        if (!$this->validator->verifyIntegrity($result)) {
            throw new SecurityException('Result integrity check failed'); 
        }
    }
}
