<?php

namespace App\Core\Database;

use Illuminate\Support\Facades\DB;
use App\Core\Security\SecurityManagerInterface;
use App\Core\Monitoring\MonitoringServiceInterface;
use App\Core\Cache\CacheManagerInterface;
use App\Exceptions\DatabaseException;

class DatabaseManager implements DatabaseManagerInterface
{
    private SecurityManagerInterface $security;
    private MonitoringServiceInterface $monitor;
    private CacheManagerInterface $cache;
    private QueryValidator $validator;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        MonitoringServiceInterface $monitor,
        CacheManagerInterface $cache,
        QueryValidator $validator,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->config = $config;
    }

    /**
     * Execute secure database query with monitoring
     */
    public function executeQuery(string $query, array $params = [], array $context = []): mixed
    {
        $operationId = $this->monitor->startOperation('database.query');

        try {
            // Validate query before execution
            $this->validator->validateQuery($query, $params);

            // Check query complexity
            $this->checkQueryComplexity($query);

            // Start performance monitoring
            $startTime = microtime(true);

            // Execute with security context
            $result = $this->executeSecureQuery($query, $params, $context);

            // Record performance metrics
            $this->recordQueryMetrics($query, microtime(true) - $startTime);

            return $result;

        } catch (\Throwable $e) {
            $this->handleQueryFailure($e, $query, $params, $operationId);
            throw $e;
        } finally {
            $this->monitor->stopOperation($operationId);
        }
    }

    /**
     * Execute transaction with comprehensive protection
     */
    public function executeTransaction(callable $callback, array $context = []): mixed
    {
        $operationId = $this->monitor->startOperation('database.transaction');

        try {
            // Start transaction with monitoring
            DB::beginTransaction();

            // Execute transaction callback with security
            $result = $this->security->executeCriticalOperation(
                $callback,
                array_merge($context, ['operation_id' => $operationId])
            );

            // Validate transaction state
            $this->validateTransactionState();

            DB::commit();
            
            $this->monitor->recordMetric('transaction.success', 1);
            
            return $result;

        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleTransactionFailure($e, $operationId);
            throw $e;
        } finally {
            $this->monitor->stopOperation($operationId);
        }
    }

    /**
     * Execute optimized bulk operation
     */
    public function executeBulkOperation(string $operation, array $data, array $context = []): int
    {
        $operationId = $this->monitor->startOperation('database.bulk');

        try {
            // Validate bulk operation
            $this->validator->validateBulkOperation($operation, $data);

            // Check resource limits
            $this->checkResourceLimits($data);

            // Execute in chunks for optimal performance
            $affected = 0;
            foreach (array_chunk($data, $this->config['bulk_chunk_size']) as $chunk) {
                $affected += $this->executeBulkChunk($operation, $chunk, $context);
            }

            $this->monitor->recordMetric('bulk_operation.rows', $affected);
            
            return $affected;

        } catch (\Throwable $e) {
            $this->handleBulkFailure($e, $operation, $operationId);
            throw $e;
        } finally {
            $this->monitor->stopOperation($operationId);
        }
    }

    private function executeSecureQuery(string $query, array $params, array $context): mixed
    {
        return $this->security->executeCriticalOperation(function() use ($query, $params) {
            return DB::select($query, $params);
        }, $context);
    }

    private function checkQueryComplexity(string $query): void
    {
        $complexity = $this->validator->analyzeQueryComplexity($query);
        
        if ($complexity > $this->config['max_query_complexity']) {
            throw new DatabaseException('Query complexity exceeds maximum threshold');
        }
    }

    private function recordQueryMetrics(string $query, float $executionTime): void
    {
        $this->monitor->recordMetric('query.execution_time', $executionTime);

        if ($executionTime > $this->config['slow_query_threshold']) {
            $this->monitor->triggerAlert('slow_query_detected', [
                'query' => $query,
                'execution_time' => $executionTime
            ]);
        }
    }

    private function validateTransactionState(): void
    {
        if (DB::transactionLevel() !== 1) {
            throw new DatabaseException('Invalid transaction nesting level');
        }

        // Additional transaction state validation
    }

    private function executeBulkChunk(string $operation, array $chunk, array $context): int
    {
        return $this->executeTransaction(function() use ($operation, $chunk) {
            return DB::table($operation)->insert($chunk);
        }, $context);
    }

    private function checkResourceLimits(array $data): void
    {
        $size = $this->calculateDataSize($data);
        
        if ($size > $this->config['max_bulk_size']) {
            throw new DatabaseException('Bulk operation exceeds size limit');
        }

        // Additional resource checks
    }

    private function handleQueryFailure(\Throwable $e, string $query, array $params, string $operationId): void
    {
        $this->monitor->recordMetric('query.failure', 1);
        
        $this->monitor->triggerAlert('query_failed', [
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'query' => $query,
            'params' => $params
        ]);
    }

    private function handleTransactionFailure(\Throwable $e, string $operationId): void
    {
        $this->monitor->recordMetric('transaction.failure', 1);
        
        $this->monitor->triggerAlert('transaction_failed', [
            'operation_id' => $operationId,
            'error' => $e->getMessage()
        ]);
    }

    private function handleBulkFailure(\Throwable $e, string $operation, string $operationId): void
    {
        $this->monitor->recordMetric('bulk_operation.failure', 1);
        
        $this->monitor->triggerAlert('bulk_operation_failed', [
            'operation_id' => $operationId,
            'operation' => $operation,
            'error' => $e->getMessage()
        ]);
    }
}
