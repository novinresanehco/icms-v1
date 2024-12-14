<?php

namespace App\Core\Operations;

class OperationExecutor implements OperationInterface 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private MonitoringService $monitor;
    private AuditLogger $logger;
    private MetricsCollector $metrics;

    public function execute(CriticalOperation $operation): OperationResult 
    {
        $startTime = microtime(true);
        
        try {
            // Pre-execution validation
            $this->validateOperation($operation);
            
            // Execute with monitoring
            $result = $this->executeWithMonitoring($operation);
            
            // Post-execution verification
            $this->verifyExecution($result);
            
            // Record metrics
            $this->recordMetrics($operation, $startTime);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->handleExecutionFailure($operation, $e);
            throw $e;
        }
    }

    private function validateOperation(CriticalOperation $operation): void 
    {
        // Security validation
        $this->security->validateOperation($operation);
        
        // Input validation
        $this->validator->validateInput($operation->getData());
        
        // Business rules validation
        $this->validator->validateBusinessRules($operation);
    }

    private function executeWithMonitoring(CriticalOperation $operation): OperationResult 
    {
        return $this->monitor->trackExecution(
            fn() => $operation->execute()
        );
    }

    private function verifyExecution(OperationResult $result): void 
    {
        if (!$result->isValid()) {
            throw new OperationException('Invalid operation result');
        }
    }

    private function recordMetrics(CriticalOperation $operation, float $startTime): void 
    {
        $executionTime = microtime(true) - $startTime;
        
        $this->metrics->record([
            'operation' => get_class($operation),
            'execution_time' => $executionTime,
            'memory_usage' => memory_get_peak_usage(true),
            'timestamp' => now()
        ]);
    }
}

class TransactionManager implements TransactionInterface 
{
    private DatabaseManager $db;
    private SecurityManager $security;
    private AuditLogger $logger;
    
    public function executeTransaction(Transaction $transaction): TransactionResult 
    {
        $this->db->beginTransaction();
        
        try {
            // Validate transaction
            $this->validateTransaction($transaction);
            
            // Execute operations
            $result = $this->executeOperations($transaction->getOperations());
            
            // Verify result
            $this->verifyTransactionResult($result);
            
            $this->db->commit();
            return $result;
            
        } catch (\Exception $e) {
            $this->db->rollback();
            $this->handleTransactionFailure($transaction, $e);
            throw $e;
        }
    }

    private function validateTransaction(Transaction $transaction): void 
    {
        foreach ($transaction->getOperations() as $operation) {
            $this->security->validateOperation($operation);
        }
    }

    private function executeOperations(array $operations): TransactionResult 
    {
        $results = [];
        
        foreach ($operations as $operation) {
            $results[] = $operation->execute();
        }
        
        return new TransactionResult($results);
    }
}

class BatchOperationManager implements BatchInterface 
{
    private OperationExecutor $executor;
    private ValidationService $validator;
    private MonitoringService $monitor;

    public function executeBatch(array $operations): BatchResult 
    {
        // Validate batch
        $this->validateBatch($operations);
        
        // Execute operations
        $results = [];
        foreach ($operations as $operation) {
            try {
                $results[] = $this->executor->execute($operation);
            } catch (\Exception $e) {
                $this->handleBatchFailure($operations, $results, $e);
                throw $e;
            }
        }
        
        return new BatchResult($results);
    }

    private function validateBatch(array $operations): void 
    {
        foreach ($operations as $operation) {
            $this->validator->validateOperation($operation);
        }
    }

    private function handleBatchFailure(array $operations, array $results, \Exception $e): void 
    {
        // Rollback completed operations if needed
        foreach ($results as $result) {
            $this->rollbackOperation($result);
        }
        
        $this->monitor->recordBatchFailure($operations, $e);
    }
}
