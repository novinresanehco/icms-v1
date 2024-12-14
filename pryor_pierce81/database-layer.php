```php
namespace App\Core\Database;

class CriticalDatabaseManager {
    private SecurityManager $security;
    private ValidationEngine $validator;
    private TransactionManager $transaction;
    private QueryLogger $logger;

    public function executeQuery(string $operation, callable $query): mixed {
        // Start transaction tracking
        $transactionId = $this->transaction->begin();
        
        try {
            // Pre-execution validation
            $this->security->validateDatabaseOperation($operation);
            $this->validator->validateQuery($operation);
            
            // Execute with monitoring
            $result = $this->monitorQuery($operation, $query);
            
            // Validate result
            $this->validator->validateResult($operation, $result);
            
            // Commit transaction
            $this->transaction->commit($transactionId);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->transaction->rollback($transactionId);
            $this->handleQueryFailure($operation, $e);
            throw $e;
        }
    }

    private function monitorQuery(string $operation, callable $query): mixed {
        $startTime = microtime(true);
        
        try {
            $result = $query();
            $executionTime = microtime(true) - $startTime;
            
            $this->logger->logQuery($operation, [
                'execution_time' => $executionTime,
                'memory_usage' => memory_get_usage(true)
            ]);
            
            return $result;
            
        } catch (QueryException $e) {
            throw new DatabaseException(
                "Query execution failed: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    private function handleQueryFailure(string $operation, \Exception $e): void {
        $this->logger->logQueryFailure($operation, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

class TransactionManager {
    private PDO $connection;
    private QueryLogger $logger;
    
    public function begin(): string {
        $id = uniqid('transaction_', true);
        
        $this->connection->beginTransaction();
        $this->logger->logTransaction("begin:$id");
        
        return $id;
    }

    public function commit(string $id): void {
        try {
            $this->connection->commit();
            $this->logger->logTransaction("commit:$id");
            
        } catch (PDOException $e) {
            throw new TransactionException(
                "Failed to commit transaction: {$e->getMessage()}",
                0,
                $e
            );
        }
    }

    public function rollback(string $id): void {
        try {
            $this->connection->rollBack();
            $this->logger->logTransaction("rollback:$id");
            
        } catch (PDOException $e) {
            throw new TransactionException(
                "Failed to rollback transaction: {$e->getMessage()}",
                0,
                $e
            );
        }
    }
}

class QueryBuilder {
    private ValidationEngine $validator;
    private SecurityManager $security;
    
    public function build(string $operation, array $params): string {
        // Validate query parameters
        $this->validator->validateQueryParams($operation, $params);
        
        // Apply security filters
        $params = $this->security->sanitizeQueryParams($params);
        
        // Build query
        return match($operation) {
            'select' => $this->buildSelect($params),
            'insert' => $this->buildInsert($params),
            'update' => $this->buildUpdate($params),
            'delete' => $this->buildDelete($params),
            default => throw new InvalidQueryException()
        };
    }

    private function buildSelect(array $params): string {
        return sprintf(
            'SELECT %s FROM %s WHERE %s',
            $this->buildColumns($params['columns']),
            $this->escapeTable($params['table']),
            $this->buildConditions($params['conditions'])
        );
    }

    private function buildInsert(array $params): string {
        return sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->escapeTable($params['table']),
            $this->buildColumns($params['columns']),
            $this->buildValues($params['values'])
        );
    }
}

class QueryLogger {
    private LogManager $logger;
    private MetricsCollector $metrics;
    
    public function logQuery(string $operation, array $metrics): void {
        $this->logger->log('query', array_merge($metrics, [
            'operation' => $operation,
            'timestamp' => microtime(true)
        ]));
        
        $this->metrics->recordQueryMetrics($operation, $metrics);
    }

    public function logQueryFailure(string $operation, array $context): void {
        $this->logger->logError('query_failure', array_merge($context, [
            'operation' => $operation,
            'timestamp' => microtime(true)
        ]));
        
        $this->metrics->recordQueryFailure($operation);
    }
}

interface ValidationEngine {
    public function validateQuery(string $operation): void;
    public function validateQueryParams(string $operation, array $params): void;
    public function validateResult(string $operation, $result): void;
}

interface SecurityManager {
    public function validateDatabaseOperation(string $operation): void;
    public function sanitizeQueryParams(array $params): array;
}

interface LogManager {
    public function log(string $type, array $data): void;
    public function logError(string $type, array $context): void;
}

class DatabaseException extends \Exception {}
class TransactionException extends \Exception {}
class InvalidQueryException extends \Exception {}
```
