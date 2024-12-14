<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use App\Core\Services\{
    ValidationService,
    EncryptionService,
    AuditService,
    MonitoringService 
};

class SecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditService $audit;
    private MonitoringService $monitor;
    
    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditService $audit,
        MonitoringService $monitor
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->audit = $audit;
        $this->monitor = $monitor;
    }

    public function executeCriticalOperation(CriticalOperation $operation): OperationResult 
    {
        $monitorId = $this->monitor->startOperation();

        try {
            DB::beginTransaction();

            // Pre-execution validation
            $this->validateOperation($operation);

            // Execute with full monitoring
            $result = $this->executeWithProtection($operation, $monitorId);

            // Post-execution verification 
            $this->verifyResult($result);

            DB::commit();
            $this->audit->logSuccess($operation, $result);

            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $operation);
            throw $e;
        } finally {
            $this->monitor->stopOperation($monitorId);
        }
    }

    private function validateOperation(CriticalOperation $operation): void
    {
        if (!$this->validator->validateOperation($operation)) {
            throw new ValidationException('Operation validation failed');
        }

        if (!$this->validator->checkSecurityConstraints($operation)) {
            throw new SecurityException('Security constraints not met');
        }
    }

    private function executeWithProtection(CriticalOperation $operation, string $monitorId): OperationResult
    {
        return $this->monitor->track($monitorId, function() use ($operation) {
            $result = $operation->execute();
            
            if (!$result) {
                throw new OperationException('Operation failed');
            }

            return $result;
        });
    }

    private function verifyResult(OperationResult $result): void
    {
        if (!$this->validator->verifyResult($result)) {
            throw new ValidationException('Result verification failed');
        }
    }

    private function handleFailure(\Exception $e, CriticalOperation $operation): void
    {
        $this->audit->logFailure($e, $operation);
        
        if ($this->isSystemCritical($e)) {
            $this->executeEmergencyProtocol($e);
        }
    }

    private function isSystemCritical(\Exception $e): bool
    {
        return $e instanceof SystemCriticalException || 
               $e instanceof SecurityException ||
               $e instanceof IntegrityException;
    }

    private function executeEmergencyProtocol(\Exception $e): void
    {
        // Execute emergency procedures
        // Should be customized based on system requirements
    }
}
