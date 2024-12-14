namespace App\Core\Security;

use App\Core\Contracts\SecurityManagerInterface;
use App\Core\Services\{ValidationService, EncryptionService, AuditLogger};
use App\Core\Exceptions\{SecurityException, ValidationException};
use Illuminate\Support\Facades\DB;

class CoreSecurityManager implements SecurityManagerInterface
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger; 
    private array $securityConfig;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        array $securityConfig
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->securityConfig = $securityConfig;
    }

    public function executeSecureOperation(callable $operation, array $context): mixed 
    {
        $this->validatePreConditions($context);
        
        DB::beginTransaction();
        $startTime = microtime(true);
        
        try {
            $result = $this->executeWithProtection($operation);
            
            $this->validateResult($result);
            $this->auditSuccess($context, $result);
            
            DB::commit();
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw new SecurityException(
                'Operation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            $this->recordMetrics($context, microtime(true) - $startTime);
        }
    }

    private function validatePreConditions(array $context): void
    {
        if (!$this->validator->validateContext($context)) {
            throw new ValidationException('Invalid operation context');
        }

        if (!$this->validator->checkSecurityConstraints($context)) {
            throw new SecurityException('Security constraints not met');
        }
    }

    private function executeWithProtection(callable $operation): mixed
    {
        return DB::transaction(function() use ($operation) {
            return $operation();
        });
    }

    private function validateResult($result): void 
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Result validation failed');
        }
    }

    private function handleFailure(\Exception $e, array $context): void
    {
        $this->auditLogger->logFailure($e, $context, [
            'stack_trace' => $e->getTraceAsString(),
            'system_state' => $this->captureSystemState()
        ]);
    }

    private function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_peak_usage(true),
            'cpu_load' => sys_getloadavg()[0],
            'active_transactions' => DB::transactionLevel(),
            'timestamp' => microtime(true)
        ];
    }

    private function recordMetrics(array $context, float $executionTime): void
    {
        $metrics = [
            'execution_time' => $executionTime,
            'memory_usage' => memory_get_peak_usage(true),
            'timestamp' => microtime(true)
        ];
        
        $this->auditLogger->logMetrics($context, $metrics);
    }

    private function auditSuccess(array $context, $result): void
    {
        $this->auditLogger->logSuccess($context, [
            'result_hash' => hash('sha256', serialize($result)),
            'timestamp' => microtime(true)
        ]);
    }
}
