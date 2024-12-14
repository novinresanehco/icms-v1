<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use App\Core\Audit\AuditLogger;
use App\Core\Validation\ValidationService;
use App\Core\Encryption\EncryptionService;
use App\Core\Monitoring\SystemMonitor;

class SecurityCoreManager implements SecurityManagerInterface
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private SystemMonitor $monitor;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        SystemMonitor $monitor
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->monitor = $monitor;
    }

    public function executeCriticalOperation(SecurityOperation $operation): OperationResult
    {
        DB::beginTransaction();
        
        try {
            $this->monitor->startOperation($operation);
            
            // Pre-execution validation
            $this->validateOperation($operation);
            
            // Execute with protection
            $result = $this->executeProtectedOperation($operation);
            
            // Verify result
            $this->verifyResult($result);
            
            DB::commit();
            $this->auditLogger->logSuccess($operation);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $operation);
            throw $e;
        } finally {
            $this->monitor->endOperation($operation);
        }
    }

    private function validateOperation(SecurityOperation $operation): void
    {
        if (!$this->validator->validateSecurityOperation($operation)) {
            throw new SecurityValidationException('Invalid security operation');
        }

        if (!$this->validator->verifyPermissions($operation)) {
            throw new SecurityAccessException('Insufficient permissions');
        }
    }

    private function executeProtectedOperation(SecurityOperation $operation): OperationResult
    {
        $encryptedData = $this->encryption->encryptOperation($operation);
        
        return new OperationResult(
            $operation->execute($encryptedData),
            $this->generateResultMetadata($operation)
        );
    }

    private function verifyResult(OperationResult $result): void
    {
        if (!$result->isValid()) {
            throw new SecurityResultException('Invalid operation result');
        }

        if (!$this->validator->verifyResultIntegrity($result)) {
            throw new SecurityIntegrityException('Result integrity check failed');
        }
    }

    private function handleFailure(\Exception $e, SecurityOperation $operation): void
    {
        $this->auditLogger->logFailure($e, [
            'operation' => $operation->getId(),
            'type' => $operation->getType(),
            'user' => $operation->getUserContext(),
            'timestamp' => now()
        ]);
        
        if ($this->isCriticalFailure($e)) {
            $this->handleCriticalFailure($e, $operation);
        }
    }

    private function isCriticalFailure(\Exception $e): bool
    {
        return $e instanceof CriticalSecurityException ||
               $e instanceof SystemIntegrityException;
    }

    private function handleCriticalFailure(\Exception $e, SecurityOperation $operation): void
    {
        $this->monitor->triggerCriticalAlert($e, $operation);
        $this->auditLogger->logCriticalFailure($e, $operation);
    }

    private function generateResultMetadata(SecurityOperation $operation): array
    {
        return [
            'timestamp' => now(),
            'operation_id' => $operation->getId(),
            'user_context' => $operation->getUserContext(),
            'system_state' => $this->monitor->captureState()
        ];
    }
}
