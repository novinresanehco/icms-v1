<?php

namespace App\Core\Operations;

use App\Core\Security\SecurityManager;
use App\Core\Services\{ValidationService, AuditService, MonitoringService};
use App\Core\Exceptions\{OperationException, SecurityException, ValidationException};
use Illuminate\Support\Facades\DB;

class CriticalOperationManager
{
    private SecurityManager $security;
    private ValidationService $validator;
    private AuditService $audit;
    private MonitoringService $monitor;
    private array $config;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        AuditService $audit,
        MonitoringService $monitor,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->monitor = $monitor;
        $this->config = $config;
    }

    public function executeOperation(CriticalOperation $operation): mixed
    {
        $operationId = $this->generateOperationId();
        $context = $operation->getContext();

        $this->monitor->startOperation($operationId);
        
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validatePreConditions($operation);
            
            // Execute with monitoring
            $result = $this->executeWithProtection($operation, $operationId);
            
            // Post-execution verification
            $this->verifyResult($operation, $result);
            
            // Commit if all validations pass
            DB::commit();
            
            $this->audit->logSuccess($operation, $result);
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            $this->handleFailure($operation, $e, $operationId);
            
            throw new OperationException(
                'Critical operation failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } finally {
            $this->monitor->endOperation($operationId);
        }
    }

    protected function validatePreConditions(CriticalOperation $operation): void
    {
        // Security validation
        if (!$this->security->validateOperation($operation->getContext())) {
            throw new SecurityException('Security validation failed');
        }

        // Input validation
        if (!$this->validator->validateInput($operation->getData())) {
            throw new ValidationException('Input validation failed');
        }

        // System state validation
        if (!$this->validator->validateSystemState()) {
            throw new ValidationException('System state invalid for operation');
        }

        // Resource check
        if (!$this->checkResourceAvailability($operation)) {
            throw new OperationException('Insufficient resources for operation');
        }
    }

    protected function executeWithProtection(
        CriticalOperation $operation,
        string $operationId
    ): mixed {
        return $this->monitor->trackExecution(
            $operationId,
            function() use ($operation) {
                // Pre-execution hooks
                $this->runPreExecutionHooks($operation);
                
                // Execute operation
                $result = $operation->execute();
                
                // Post-execution hooks
                $this->runPostExecutionHooks($operation, $result);
                
                return $result;
            }
        );
    }

    protected function verifyResult(CriticalOperation $operation, $result): void
    {
        // Verify result integrity
        if (!$this->validator->validateResult($result)) {
            throw new ValidationException('Result validation failed');
        }

        // Verify business rules
        if (!$this->validator->validateBusinessRules($operation, $result)) {
            throw new ValidationException('Business rule validation failed');
        }

        // Verify system constraints
        if (!$this->validator->validateSystemConstraints($result)) {
            throw new ValidationException('System constraint validation failed');
        }
    }

    protected function handleFailure(
        CriticalOperation $operation,
        \Exception $e,
        string $operationId
    ): void {
        $this->audit->logFailure($e, $operation);
        $this->monitor->recordFailure($operationId, $e);
        $this->executeFailureRecovery($operation, $e);
    }

    private function generateOperationId(): string
    {
        return uniqid('op_', true);
    }

    private function checkResourceAvailability(CriticalOperation $operation): bool
    {
        $requiredResources = $operation->getRequiredResources();
        $availableResources = $this->monitor->getAvailableResources();
        
        foreach ($requiredResources as $resource => $required) {
            if (!isset($availableResources[$resource]) || 
                $availableResources[$resource] < $required) {
                return false;
            }
        }
        
        return true;
    }

    private function runPreExecutionHooks(CriticalOperation $operation): void
    {
        foreach ($this->config['pre_execution_hooks'] as $hook) {
            $hook->execute($operation);
        }
    }

    private function runPostExecutionHooks(CriticalOperation $operation, $result): void
    {
        foreach ($this->config['post_execution_hooks'] as $hook) {
            $hook->execute($operation, $result);
        }
    }

    private function executeFailureRecovery(CriticalOperation $operation, \Exception $e): void
    {
        foreach ($this->config['failure_recovery_handlers'] as $handler) {
            $handler->handle($operation, $e);
        }
    }
}
