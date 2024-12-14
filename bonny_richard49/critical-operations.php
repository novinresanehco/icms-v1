<?php

namespace App\Core\Operations;

use App\Core\Security\SecurityContext;
use App\Core\Validation\ValidationManager;
use App\Core\Audit\AuditManager;
use Illuminate\Support\Facades\DB;

class OperationExecutor implements OperationExecutorInterface 
{
    private SecurityContext $security;
    private ValidationManager $validator;
    private AuditManager $audit;
    private MetricsCollector $metrics;

    public function __construct(
        SecurityContext $security,
        ValidationManager $validator,
        AuditManager $audit,
        MetricsCollector $metrics
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->metrics = $metrics;
    }

    public function execute(CriticalOperation $operation): OperationResult 
    {
        $startTime = microtime(true);
        
        DB::beginTransaction();
        
        try {
            // Pre-execution validation
            $this->validator->validateCriticalOperation($operation);
            
            // Execute operation
            $result = $this->executeOperation($operation);
            
            // Validate result
            $this->validateResult($result);
            
            // Commit transaction
            DB::commit();
            
            // Record metrics
            $this->metrics->recordSuccess(
                $operation->getType(),
                microtime(true) - $startTime
            );
            
            return $result;
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleFailure($e, $operation);
            throw $e;
        }
    }

    private function executeOperation(CriticalOperation $operation): OperationResult 
    {
        // Create execution context
        $context = $this->createExecutionContext($operation);
        
        // Log operation start
        $this->audit->logOperationStart($operation, $context);
        
        try {
            // Execute with security context
            $result = $this->security->executeSecure(
                fn() => $operation->execute(),
                $context
            );
            
            // Log successful completion
            $this->audit->logOperationSuccess($operation, $result, $context);
            
            return $result;
            
        } catch (\Exception $e) {
            // Log failure
            $this->audit->logOperationFailure($operation, $e, $context);
            throw $e;
        }
    }

    private function validateResult(OperationResult $result): void 
    {
        if (!$result->isValid()) {
            throw new InvalidResultException(
                'Operation produced invalid result',
                $result->getErrors()
            );
        }
    }

    private function createExecutionContext(CriticalOperation $operation): ExecutionContext 
    {
        return new ExecutionContext([
            'operation_id' => uniqid('op_', true),
            'operation_type' => $operation->getType(),
            'started_at' => microtime(true),
            'security_context' => $this->security->getContext()
        ]);
    }

    private function handleFailure(\Exception $e, CriticalOperation $operation): void 
    {
        // Record failure metrics
        $this->metrics->recordFailure(
            $operation->getType(),
            $e->getCode()
        );
        
        // Log critical failure
        $this->audit->logCriticalFailure($operation, $e);
        
        // Execute failure recovery if needed
        $this->executeFailureRecovery($operation, $e);
    }

    private function executeFailureRecovery(
        CriticalOperation $operation,
        \Exception $e
    ): void {
        try {
            $operation->handleFailure($e);
        } catch (\Exception $recoveryException) {
            // Log recovery failure
            $this->audit->logRecoveryFailure($operation, $recoveryException);
        }
    }
}

class ExecutionContext 
{
    private array $data;

    public function __construct(array $data) 
    {
        $this->data = $data;
    }

    public function get(string $key, $default = null) 
    {
        return $this->data[$key] ?? $default;
    }

    public function toArray(): array 
    {
        return $this->data;
    }
}

class Operation