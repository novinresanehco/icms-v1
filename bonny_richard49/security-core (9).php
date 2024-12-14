<?php

namespace App\Core\Security;

class SecurityManager implements SecurityManagerInterface 
{
    protected ValidationService $validator;
    protected EncryptionService $encryption;
    protected AuditLogger $auditLogger;
    protected AccessControl $accessControl;

    public function executeCriticalOperation(CriticalOperation $operation): OperationResult
    {
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validateOperation($operation);
            
            // Execute with monitoring
            $result = $this->executeWithProtection($operation);
            
            // Post-execution verification
            $this->verifyResult($result);
            
            DB::commit();
            $this->auditLogger->logSuccess($operation, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($operation, $e);
            throw new SecurityException(
                'Critical operation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    protected function validateOperation(CriticalOperation $operation): void
    {
        // Validate input data
        $this->validator->validateInput(
            $operation->getData(),
            $operation->getValidationRules()
        );

        // Verify permissions
        if (!$this->accessControl->hasPermission($operation->getRequiredPermissions())) {
            throw new UnauthorizedException('Insufficient permissions');
        }

        // Additional security checks
        $this->validator->verifySecurityConstraints($operation);
    }

    protected function executeWithProtection(CriticalOperation $operation): OperationResult
    {
        $monitor = new OperationMonitor($operation);
        
        try {
            return $monitor->execute(fn() => $operation->execute());
        } catch (\Exception $e) {
            $monitor->recordFailure($e);
            throw $e;
        }
    }

    protected function verifyResult(OperationResult $result): void
    {
        if (!$this->validator->verifyIntegrity($result)) {
            throw new IntegrityException('Result integrity check failed');
        }

        if (!$this->validator->verifyBusinessRules($result)) {
            throw new BusinessRuleException('Business rule validation failed');
        }
    }

    protected function handleFailure(CriticalOperation $operation, \Exception $e): void
    {
        // Log detailed failure information
        $this->auditLogger->logFailure($operation, $e, [
            'stack_trace' => $e->getTraceAsString(),
            'input_data' => $operation->getData(),
            'system_state' => $this->captureSystemState()
        ]);

        // Execute recovery procedures
        $this->executeFailureRecovery($operation, $e);
    }

    protected function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'cpu_usage' => sys_getloadavg()[0],
            'active_connections' => DB::getConnections(),
            'cache_status' => Cache::getStatistics()
        ];
    }
}
