namespace App\Core\Security;

use App\Core\Interfaces\SecurityInterface;
use App\Core\Security\{AccessControl, AuditLogger, EncryptionService};
use App\Core\Exceptions\{SecurityException, ValidationException};
use Illuminate\Support\Facades\{DB, Log};

class SecurityManager implements SecurityInterface
{
    protected AccessControl $accessControl;
    protected AuditLogger $auditLogger;
    protected EncryptionService $encryption;
    
    public function __construct(
        AccessControl $accessControl,
        AuditLogger $auditLogger, 
        EncryptionService $encryption
    ) {
        $this->accessControl = $accessControl;
        $this->auditLogger = $auditLogger;
        $this->encryption = $encryption;
    }

    public function validateOperation(array $context): bool
    {
        DB::beginTransaction();
        
        try {
            $this->validateAccess($context);
            $this->validateData($context);
            $this->auditLogger->logAccess($context);
            
            DB::commit();
            return true;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleSecurityFailure($e, $context);
            throw $e;
        }
    }

    public function executeSecure(callable $operation, array $context): mixed
    {
        $this->validateOperation($context);
        
        try {
            $result = $operation();
            $this->auditLogger->logSuccess($context);
            return $result;
            
        } catch (\Exception $e) {
            $this->auditLogger->logFailure($e, $context);
            throw new SecurityException('Secure operation failed', 0, $e);
        }
    }

    protected function validateAccess(array $context): void
    {
        if (!$this->accessControl->hasPermission($context)) {
            throw new SecurityException('Unauthorized access attempt');
        }
    }

    protected function validateData(array $context): void
    {
        if (!isset($context['data']) || !is_array($context['data'])) {
            throw new ValidationException('Invalid operation data');
        }

        foreach ($context['data'] as $key => $value) {
            if (!$this->encryption->verifyData($value)) {
                throw new SecurityException("Data integrity check failed for: $key");
            }
        }
    }

    protected function handleSecurityFailure(\Exception $e, array $context): void
    {
        Log::error('Security validation failed', [
            'error' => $e->getMessage(),
            'context' => $context,
            'trace' => $e->getTraceAsString()
        ]);

        if ($this->isCriticalFailure($e)) {
            $this->notifySecurityTeam($e, $context);
        }
    }

    protected function isCriticalFailure(\Exception $e): bool
    {
        return $e instanceof SecurityException 
            || $e->getCode() >= 500;
    }

    protected function notifySecurityTeam(\Exception $e, array $context): void
    {
        // Critical security alert implementation
    }
}

trait SecureOperations
{
    protected SecurityManager $security;
    
    protected function executeSecure(callable $operation, array $context = []): mixed
    {
        return $this->security->executeSecure($operation, $context);
    }
    
    protected function validateAccess(array $context = []): void
    {
        $this->security->validateOperation($context);
    }
}
