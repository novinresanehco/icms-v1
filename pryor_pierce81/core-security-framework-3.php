<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use App\Core\Contracts\SecurityManagerInterface;
use App\Core\Exceptions\{SecurityException, ValidationException};

class CoreSecurityManager implements SecurityManagerInterface 
{
    protected ValidationService $validator;
    protected EncryptionService $encryption; 
    protected AuditLogger $auditLogger;
    protected AccessControl $accessControl;
    
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

    public function executeCriticalOperation(callable $operation): mixed
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation();
            
            // Execute with monitoring
            $startTime = microtime(true);
            $result = $this->executeWithProtection($operation);
            $executionTime = microtime(true) - $startTime;
            
            // Validate result
            $this->validateResult($result);
            
            // Log success and commit
            $this->logSuccess($result, $executionTime);
            DB::commit();
            
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleFailure($e);
            throw new SecurityException('Operation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function validateOperation(): void 
    {
        if (!$this->accessControl->validateCurrentAccess()) {
            throw new SecurityException('Access denied');
        }

        if (!$this->validator->validateCurrentState()) {
            throw new ValidationException('Invalid system state');
        }
    }

    protected function executeWithProtection(callable $operation): mixed
    {
        return $this->encryption->executeEncrypted($operation);
    }

    protected function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Invalid operation result');
        }
    }

    protected function logSuccess($result, float $executionTime): void
    {
        $this->auditLogger->logSuccess([
            'execution_time' => $executionTime,
            'result_hash' => hash('sha256', serialize($result)),
            'user' => auth()->id(),
            'timestamp' => now()
        ]);
    }

    protected function handleFailure(\Throwable $e): void
    {
        $this->auditLogger->logFailure([
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'user' => auth()->id(),
            'timestamp' => now()
        ]);
    }

    public function validateAccess(string $resource, string $action): bool
    {
        $access = $this->accessControl->checkPermission($resource, $action);
        
        $this->auditLogger->logAccess([
            'resource' => $resource,
            'action' => $action,
            'granted' => $access,
            'user' => auth()->id(),
            'timestamp' => now()
        ]);

        return $access;
    }
}
