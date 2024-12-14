<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use App\Core\Interfaces\SecurityManagerInterface;
use App\Core\Services\{ValidationService, EncryptionService, AuditLogger};
use App\Core\Exceptions\{SecurityException, ValidationException};

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

    public function executeCriticalOperation(callable $operation, array $context): mixed
    {
        DB::beginTransaction();
        try {
            // Pre-operation validation
            $this->validateOperation($context);
            
            // Execute with monitoring
            $startTime = microtime(true);
            $result = $operation();
            $executionTime = microtime(true) - $startTime;
            
            // Post-operation validation
            $this->validateResult($result);
            
            // Log success and commit
            $this->logSuccess($context, $result, $executionTime);
            DB::commit();
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw new SecurityException('Critical operation failed', 0, $e);
        }
    }

    private function validateOperation(array $context): void 
    {
        if (!$this->validator->validateContext($context)) {
            throw new ValidationException('Invalid operation context');
        }

        if (!$this->validator->validateSecurityConstraints($context)) {
            throw new SecurityException('Security constraints not met');
        }
    }

    private function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Operation result validation failed');
        }
    }

    private function logSuccess(array $context, $result, float $executionTime): void
    {
        $this->auditLogger->logSuccess([
            'context' => $context,
            'result' => $result,
            'execution_time' => $executionTime,
            'timestamp' => time()
        ]);
    }

    private function handleFailure(\Exception $e, array $context): void
    {
        $this->auditLogger->logFailure([
            'context' => $context,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => time()
        ]);
    }
}
