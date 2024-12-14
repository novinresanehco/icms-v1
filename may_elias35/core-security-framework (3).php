namespace App\Core\Security;

use App\Core\Exceptions\SecurityException;
use App\Core\Services\ValidationService;
use App\Core\Services\AuditService;
use Illuminate\Support\Facades\DB;

class CoreSecurityManager
{
    private ValidationService $validator;
    private AuditService $audit;
    private array $config;

    public function __construct(
        ValidationService $validator,
        AuditService $audit,
        array $config
    ) {
        $this->validator = $validator;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function executeSecureOperation(callable $operation, array $context): mixed
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($context);
            
            // Execute with monitoring
            $result = $this->executeWithMonitoring($operation);
            
            // Validate result
            $this->validateResult($result);
            
            DB::commit();
            $this->audit->logSuccess($context);
            
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw $e;
        }
    }

    protected function validateOperation(array $context): void 
    {
        if (!$this->validator->validate($context)) {
            throw new SecurityException('Invalid operation context');
        }

        if (!$this->checkSecurityConstraints($context)) {
            throw new SecurityException('Security constraints not met');
        }
    }

    protected function executeWithMonitoring(callable $operation): mixed
    {
        $startTime = microtime(true);
        
        try {
            return $operation();
        } finally {
            $executionTime = microtime(true) - $startTime;
            $this->audit->logPerformance($executionTime);
        }
    }

    protected function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new SecurityException('Operation result validation failed');
        }
    }

    protected function handleFailure(\Throwable $e, array $context): void
    {
        $this->audit->logFailure($e, $context);
    }

    protected function checkSecurityConstraints(array $context): bool
    {
        return $this->validator->checkPermissions($context) && 
               $this->validator->verifyIntegrity($context) &&
               $this->validator->validateInput($context);
    }
}
