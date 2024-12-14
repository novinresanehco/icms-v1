<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Core\Interfaces\SecurityManagerInterface;
use App\Core\Services\{
    ValidationService,
    EncryptionService,
    AuditService,
    MonitoringService
};

class CoreSecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditService $auditor;
    private MonitoringService $monitor;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditService $auditor,
        MonitoringService $monitor
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditor = $auditor;
        $this->monitor = $monitor;
    }

    public function executeCriticalOperation(CriticalOperation $operation): OperationResult
    {
        $monitoringId = $this->monitor->startOperation($operation);
        DB::beginTransaction();

        try {
            $this->validateOperation($operation);
            $result = $this->executeWithProtection($operation, $monitoringId);
            $this->verifyResult($result);
            
            DB::commit();
            $this->auditor->logSuccess($operation, $result);
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $operation, $monitoringId);
            throw new SecurityException('Critical operation failed', 0, $e);
        } finally {
            $this->monitor->stopOperation($monitoringId);
        }
    }

    private function validateOperation(CriticalOperation $operation): void
    {
        if (!$this->validator->validate($operation->getData())) {
            throw new ValidationException('Operation validation failed');
        }

        if (!$this->validator->checkSecurityConstraints($operation)) {
            throw new SecurityException('Security constraints not met');
        }
    }

    private function executeWithProtection(
        CriticalOperation $operation,
        string $monitoringId
    ): OperationResult {
        return $this->monitor->track($monitoringId, function() use ($operation) {
            $result = $operation->execute();
            
            if (!$result->isValid()) {
                throw new OperationException('Invalid operation result');
            }
            
            return $result;
        });
    }

    private function verifyResult(OperationResult $result): void
    {
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Result validation failed');
        }
    }

    private function handleFailure(
        \Exception $e,
        CriticalOperation $operation,
        string $monitoringId
    ): void {
        Log::emergency('Critical operation failed', [
            'operation' => $operation->toArray(),
            'monitoring_id' => $monitoringId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->auditor->logFailure($operation, $e);

        try {
            $this->executeEmergencyProtocol($e, $operation);
        } catch (\Exception $emergencyError) {
            Log::emergency('Emergency protocol failed', [
                'error' => $emergencyError->getMessage()
            ]);
        }
    }

    private function executeEmergencyProtocol(
        \Exception $e,
        CriticalOperation $operation
    ): void {
        // Implement emergency procedures here
        // This should be customized based on specific system needs
    }
}
