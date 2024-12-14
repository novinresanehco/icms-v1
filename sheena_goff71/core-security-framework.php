<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Core\Exceptions\SecurityException;

class CoreSecurityManager
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;

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

    public function executeSecureOperation(callable $operation, array $context): mixed
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($context);
            
            // Execute with monitoring
            $result = $this->executeWithProtection($operation);
            
            // Validate result
            $this->validateResult($result);
            
            DB::commit();
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $context);
            throw $e;
        }
    }

    private function validateOperation(array $context): void 
    {
        if (!$this->validator->validateContext($context)) {
            throw new SecurityException('Invalid operation context');
        }

        if (!$this->accessControl->checkPermissions($context)) {
            throw new SecurityException('Insufficient permissions');
        }
    }

    private function executeWithProtection(callable $operation): mixed
    {
        $monitoringId = $this->startMonitoring();
        
        try {
            return $operation();
        } finally {
            $this->stopMonitoring($monitoringId);
        }
    }

    private function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new SecurityException('Operation result validation failed');
        }
    }

    private function handleFailure(\Exception $e, array $context): void
    {
        $this->auditLogger->logFailure($e, $context);
    }

    private function startMonitoring(): string
    {
        return $this->auditLogger->startOperation();
    }

    private function stopMonitoring(string $id): void
    {
        $this->auditLogger->stopOperation($id);
    }
}
