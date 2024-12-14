<?php

namespace App\Core\Security;

use App\Core\Interfaces\SecurityManagerInterface;
use App\Core\Services\{
    ValidationService,
    EncryptionService,
    AuditLogger,
    AccessControl,
    MonitoringService
};
use App\Core\Exceptions\{
    SecurityException,
    ValidationException,
    UnauthorizedException
};
use Illuminate\Support\Facades\DB;

class CoreSecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private EncryptionService $encryption;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;
    private MonitoringService $monitor;

    public function __construct(
        ValidationService $validator,
        EncryptionService $encryption,
        AuditLogger $auditLogger,
        AccessControl $accessControl,
        MonitoringService $monitor
    ) {
        $this->validator = $validator;
        $this->encryption = $encryption;
        $this->auditLogger = $auditLogger;
        $this->accessControl = $accessControl;
        $this->monitor = $monitor;
    }

    public function executeCriticalOperation(CriticalOperation $operation): OperationResult 
    {
        $startTime = microtime(true);
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($operation);
            
            // Execute with comprehensive monitoring
            $result = $this->executeWithProtection($operation);
            
            // Post-execution verification
            $this->verifyResult($result);
            
            DB::commit();
            $this->logSuccess($operation, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($operation, $e);
            throw new SecurityException(
                'Critical operation failed: ' . $e->getMessage(),
                previous: $e
            );
        } finally {
            $this->monitor->recordMetrics($operation, microtime(true) - $startTime);
        }
    }

    private function validateOperation(CriticalOperation $operation): void 
    {
        if (!$this->validator->validateInput($operation->getData())) {
            throw new ValidationException('Invalid operation input');
        }

        if (!$this->accessControl->hasPermission($operation->getRequiredPermissions())) {
            throw new UnauthorizedException('Insufficient permissions');
        }

        if (!$this->validator->verifySecurityConstraints($operation)) {
            throw new SecurityException('Security constraints not met');
        }
    }

    private function executeWithProtection(CriticalOperation $operation): OperationResult 
    {
        return $this->monitor->track(
            $operation->getId(),
            fn() => $operation->execute()
        );
    }

    private function verifyResult(OperationResult $result): void 
    {
        if (!$this->validator->validateOutput($result)) {
            throw new ValidationException('Invalid operation result');
        }

        if (!$this->validator->verifyIntegrity($result)) {
            throw new SecurityException('Result integrity check failed');
        }
    }

    private function logSuccess(CriticalOperation $operation, OperationResult $result): void 
    {
        $this->auditLogger->logSuccess(
            $operation->getId(),
            $operation->getType(),
            $result->getMetadata()
        );
    }

    private function handleFailure(CriticalOperation $operation, \Exception $e): void 
    {
        $this->auditLogger->logFailure(
            $operation->getId(),
            $operation->getType(),
            $e,
            $this->monitor->captureSystemState()
        );
    }
}
