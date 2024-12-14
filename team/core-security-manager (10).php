namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use App\Core\Security\Events\SecurityEvent;
use App\Core\Security\Interfaces\SecurityManagerInterface;
use App\Core\Security\Services\{
    AuthenticationService,
    AuthorizationService,
    EncryptionService,
    ValidationService
};

class SecurityManager implements SecurityManagerInterface
{
    private AuthenticationService $auth;
    private AuthorizationService $authz;
    private EncryptionService $encryption;
    private ValidationService $validator;
    private AuditLogger $auditLogger;

    public function __construct(
        AuthenticationService $auth,
        AuthorizationService $authz,
        EncryptionService $encryption,
        ValidationService $validator,
        AuditLogger $auditLogger
    ) {
        $this->auth = $auth;
        $this->authz = $authz;
        $this->encryption = $encryption;
        $this->validator = $validator;
        $this->auditLogger = $auditLogger;
    }

    public function executeCriticalOperation(callable $operation, array $context): mixed
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($context);
            
            // Execute operation with monitoring
            $result = $this->executeWithMonitoring($operation, $context);
            
            // Validate result
            $this->validateResult($result);
            
            DB::commit();
            $this->auditLogger->logSuccess($context, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw $e;
        }
    }

    private function validateOperation(array $context): void 
    {
        // Validate authentication
        if (!$this->auth->validateRequest($context)) {
            throw new SecurityException('Authentication failed');
        }

        // Check authorization
        if (!$this->authz->checkPermission($context)) {
            throw new SecurityException('Authorization failed');
        }

        // Validate input
        if (!$this->validator->validateInput($context)) {
            throw new ValidationException('Input validation failed');
        }
    }

    private function executeWithMonitoring(callable $operation, array $context): mixed
    {
        $startTime = microtime(true);
        
        try {
            return $operation();
        } finally {
            $executionTime = microtime(true) - $startTime;
            $this->auditLogger->logExecution($context, $executionTime);
        }
    }

    private function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Result validation failed');
        }
    }

    private function handleFailure(\Exception $e, array $context): void
    {
        $this->auditLogger->logFailure($e, $context);
        event(new SecurityEvent($e, $context));
    }
}
