<?php

namespace App\Core\Security;

use App\Core\Contracts\{SecurityManagerInterface, ValidationInterface};
use App\Core\Services\{AuditLogger, EncryptionService};
use Illuminate\Support\Facades\DB;
use App\Core\Exceptions\{SecurityException, ValidationException};

class CoreSecurityManager implements SecurityManagerInterface 
{
    private ValidationInterface $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;

    public function __construct(
        ValidationInterface $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
    }

    public function executeSecureOperation(callable $operation, array $context): mixed 
    {
        DB::beginTransaction();
        $startTime = microtime(true);

        try {
            // Pre-execution validation
            $this->validateOperation($context);
            
            // Execute with monitoring
            $result = $this->executeWithProtection($operation, $context);
            
            // Validate result
            $this->validateResult($result);
            
            DB::commit();
            $this->logSuccess($context, $result, $startTime);
            
            return $result;

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw new SecurityException('Operation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function validateOperation(array $context): void 
    {
        if (!$this->validator->validateContext($context)) {
            throw new ValidationException('Invalid operation context');
        }

        if (!$this->validator->checkSecurityConstraints($context)) {
            throw new SecurityException('Security constraints not met');
        }
    }

    private function executeWithProtection(callable $operation, array $context): mixed 
    {
        return $this->monitorExecution($operation);
    }

    private function validateResult($result): void 
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Result validation failed');
        }
    }

    private function logSuccess(array $context, $result, float $startTime): void 
    {
        $this->auditLogger->logSuccess([
            'context' => $context,
            'result' => $result,
            'execution_time' => microtime(true) - $startTime
        ]);
    }

    private function handleFailure(\Throwable $e, array $context): void 
    {
        $this->auditLogger->logFailure($e, $context);
    }

    private function monitorExecution(callable $operation): mixed 
    {
        $result = $operation();
        
        if ($result === null) {
            throw new SecurityException('Operation returned null result');
        }
        
        return $result;
    }
}
