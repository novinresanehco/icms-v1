namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use App\Core\Interfaces\SecurityManagerInterface;
use App\Core\Security\Authentication\MultiFactorAuth;
use App\Core\Security\Authorization\RoleBasedAccess;
use App\Core\Logging\AuditLogger;

class SecurityManager implements SecurityManagerInterface 
{
    private MultiFactorAuth $auth;
    private RoleBasedAccess $access;
    private AuditLogger $auditLogger;
    private ValidationService $validator;
    
    public function __construct(
        MultiFactorAuth $auth,
        RoleBasedAccess $access,
        AuditLogger $auditLogger,
        ValidationService $validator
    ) {
        $this->auth = $auth;
        $this->access = $access;
        $this->auditLogger = $auditLogger;
        $this->validator = $validator;
    }

    public function executeSecureOperation(callable $operation, array $context): mixed
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateContext($context);
            
            // Execute with monitoring
            $startTime = microtime(true);
            $result = $operation();
            $executionTime = microtime(true) - $startTime;
            
            // Validate result
            $this->validateResult($result);
            
            // Log successful operation
            $this->auditLogger->logSuccess($context, $result, $executionTime);
            
            DB::commit();
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            
            // Log failure with full context
            $this->auditLogger->logFailure($e, $context);
            
            throw new SecurityException(
                'Operation failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    public function validateAccess(string $resource, string $action): void
    {
        $user = $this->auth->getCurrentUser();
        
        if (!$this->access->canAccess($user, $resource, $action)) {
            $this->auditLogger->logUnauthorizedAccess($user, $resource, $action);
            throw new UnauthorizedException();
        }
    }

    private function validateContext(array $context): void
    {
        if (!$this->validator->validateContext($context)) {
            throw new ValidationException('Invalid operation context');
        }
    }

    private function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Operation result validation failed');
        }
    }
}

class ValidationService
{
    public function validateContext(array $context): bool
    {
        // Required context fields
        $required = ['user', 'action', 'resource'];
        
        foreach ($required as $field) {
            if (!isset($context[$field])) {
                return false;
            }
        }

        return $this->validateContextValues($context);
    }

    public function validateResult($result): bool
    {
        if ($result === null) {
            return false;
        }

        // Additional result validation logic based on operation type
        return true;
    }

    private function validateContextValues(array $context): bool
    {
        if (!$context['user'] instanceof User) {
            return false;
        }

        if (!is_string($context['action']) || empty($context['action'])) {
            return false;
        }

        if (!is_string($context['resource']) || empty($context['resource'])) {
            return false;
        }

        return true;
    }
}
