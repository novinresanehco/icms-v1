<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Core\Contracts\SecurityManagerInterface;
use App\Core\Services\{
    ValidationService,
    EncryptionService,
    AuditService
};

class SecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditService $audit;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption, 
        AuditService $audit
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->audit = $audit;
    }

    public function executeCriticalOperation(callable $operation, array $context): mixed
    {
        // Pre-execution validation
        $this->validateOperation($context);
        
        // Create audit trail
        $auditId = $this->audit->startOperation($context);
        
        DB::beginTransaction();
        
        try {
            // Execute with monitoring
            $result = $this->executeWithMonitoring($operation, $auditId);
            
            // Validate result
            $this->validateResult($result);
            
            DB::commit();
            return $result;
            
        } catch (\Throwable $e) {
            DB::rollBack();
            
            // Log failure with full context
            $this->handleFailure($e, $context, $auditId);
            
            throw $e;
        }
    }

    protected function validateOperation(array $context): void
    {
        if (!$this->validator->validateRequest($context)) {
            throw new SecurityException('Invalid operation context');
        }

        if (!$this->validator->checkSecurityConstraints($context)) {
            throw new SecurityException('Security constraints not met');
        }
    }

    protected function executeWithMonitoring(callable $operation, string $auditId): mixed
    {
        $startTime = microtime(true);

        try {
            $result = $operation();

            $this->audit->logExecution([
                'audit_id' => $auditId,
                'duration' => microtime(true) - $startTime,
                'status' => 'success'
            ]);

            return $result;

        } catch (\Throwable $e) {
            $this->audit->logExecution([
                'audit_id' => $auditId,
                'duration' => microtime(true) - $startTime,
                'status' => 'failed',
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    protected function validateResult($result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new SecurityException('Operation result validation failed');
        }
    }

    protected function handleFailure(\Throwable $e, array $context, string $auditId): void
    {
        Log::critical('Critical operation failed', [
            'exception' => $e->getMessage(),
            'context' => $context,
            'audit_id' => $auditId,
            'trace' => $e->getTraceAsString()
        ]);

        $this->audit->logFailure($e, $context, $auditId);

        // Execute emergency protocols if needed
        if ($this->isEmergencyProtocolRequired($e)) {
            $this->executeEmergencyProtocols($e);
        }
    }

    protected function isEmergencyProtocolRequired(\Throwable $e): bool
    {
        return $e instanceof CriticalSecurityException ||
               $e instanceof SystemIntegrityException;
    }

    protected function executeEmergencyProtocols(\Throwable $e): void
    {
        // Implementation depends on specific emergency requirements
        // But must be handled without throwing exceptions
    }
}
