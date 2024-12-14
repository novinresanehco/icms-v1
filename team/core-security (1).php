<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use App\Core\Interfaces\SecurityManagerInterface;
use App\Core\Services\{ValidationService, AuditLogger, AccessControl};

class CoreSecurityManager implements SecurityManagerInterface 
{
    private ValidationService $validator;
    private AuditLogger $auditLogger;
    private AccessControl $accessControl;

    public function executeCriticalOperation(CriticalOperation $operation): OperationResult 
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($operation);
            
            // Execute with monitoring
            $result = $this->executeWithMonitoring($operation);
            
            // Post-execution verification
            $this->verifyResult($result);
            
            DB::commit();
            $this->auditLogger->logSuccess($operation);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $operation);
            throw $e;
        }
    }

    private function validateOperation(CriticalOperation $operation): void 
    {
        if (!$this->validator->validate($operation->getData())) {
            throw new ValidationException('Invalid operation data');
        }

        if (!$this->accessControl->hasPermission($operation->getRequiredPermissions())) {
            throw new AccessDeniedException();
        }

        if (!$this->validator->checkSecurityConstraints($operation)) {
            throw new SecurityConstraintException();
        }
    }

    private function executeWithMonitoring(CriticalOperation $operation): OperationResult 
    {
        $startTime = microtime(true);

        try {
            $result = $operation->execute();
            
            if (!$result->isValid()) {
                throw new OperationException('Invalid operation result');
            }

            $this->recordMetrics($operation, microtime(true) - $startTime);
            
            return $result;

        } catch (\Exception $e) {
            $this->auditLogger->logOperationError($operation, $e);
            throw $e;
        }
    }

    private function verifyResult(OperationResult $result): void 
    {
        if (!$this->validator->verifyIntegrity($result)) {
            throw new IntegrityException('Result integrity check failed');
        }

        if (!$this->validator->verifyBusinessRules($result)) {
            throw new BusinessRuleException('Business rule validation failed');
        }
    }

    private function handleFailure(\Exception $e, CriticalOperation $operation): void 
    {
        $this->auditLogger->logFailure($e, $operation);

        if ($e instanceof SecurityException) {
            $this->handleSecurityFailure($e);
        }
    }

    private function recordMetrics(CriticalOperation $operation, float $duration): void 
    {
        $metrics = [
            'operation_type' => $operation->getType(),
            'duration' => $duration,
            'memory_usage' => memory_get_peak_usage(true),
            'timestamp' => time()
        ];

        $this->auditLogger->logMetrics($metrics);
    }
}
