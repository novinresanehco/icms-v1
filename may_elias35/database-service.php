<?php

namespace App\Core\Database;

use Illuminate\Support\Facades\{DB, Log, Cache};
use App\Core\Security\SecurityManager;
use App\Core\Events\DatabaseEvent;
use App\Core\Exceptions\{
    DatabaseException,
    IntegrityException,
    TransactionException
};

class DatabaseManager
{
    protected SecurityManager $security;
    protected array $config;
    protected array $metrics = [];
    protected int $transactionLevel = 0;

    public function __construct(SecurityManager $security)
    {
        $this->security = $security;
        $this->config = config('database');
        $this->initializeMetrics();
    }

    public function transaction(callable $callback, int $attempts = 1): mixed
    {
        $context = $this->createSecurityContext('transaction');
        
        try {
            $this->security->validateOperation($context);
            
            $this->beginTransaction();
            
            try {
                $result = $attempts > 1 
                    ? DB::transaction($callback, $attempts)
                    : $callback();
                
                $this->commitTransaction();
                $this->incrementMetric('successful_transactions');
                
                return $result;
                
            } catch (\Exception $e) {
                $this->rollbackTransaction();
                $this->incrementMetric('failed_transactions');
                throw $e;
            }
            
        } catch (\Exception $e) {
            $this->handleException($e, $context);
            throw new TransactionException(
                'Transaction failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    public function query(string $sql, array $bindings = [], bool $useReadConnection = false): mixed
    {
        $context = $this->createSecurityContext('query', compact('sql', 'bindings'));
        
        try {
            $this->security->validateOperation($context);
            
            $this->validateQuery($sql, $bindings);
            
            $connection = $useReadConnection 
                ? DB::connection('read')
                : DB::connection();
            
            $startTime = microtime(true);
            
            $result = $connection->select($sql, $bindings);
            
            $this->recordQueryMetrics($sql, microtime(true) - $startTime);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->handleException($e, $context);
            throw new DatabaseException(
                'Query execution failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    public function bulkInsert(string $table, array $records, int $chunkSize = 1000): int
    {
        $context = $this->createSecurityContext('bulk_insert', compact('table'));
        
        try {
            $this->security->validateOperation($context);
            
            $totalInserted = 0;
            
            $this->beginTransaction();
            
            try {
                foreach (array_chunk($records, $chunkSize) as $chunk) {
                    $inserted = DB::table($table)->insert($chunk);
                    $totalInserted += count($chunk);
                }
                
                $this->commitTransaction();
                $this->incrementMetric('bulk_inserts');
                
                return $totalInserted;
                
            } catch (\Exception $e) {
                $this->rollbackTransaction();
                throw $e;
            }
            
        } catch (\Exception $e) {
            $this->handleException($e, $context);
            throw new DatabaseException(
                'Bulk insert failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    public function checkIntegrity(string $table, array $constraints = []): bool
    {
        $context = $this->createSecurityContext('integrity_check', compact('table'));
        
        try {
            $this->security->validateOperation($context);
            
            foreach ($constraints as $constraint) {
                if (!$this->validateConstraint($table, $constraint)) {
                    throw new IntegrityException("Constraint failed: {$constraint}");
                }
            }
            
            $this->incrementMetric('integrity_checks');
            
            return true;
            
        } catch (\Exception $e) {
            $this->handleException($e, $context);
            throw new IntegrityException(
                'Integrity check failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    public function getMetrics(): array
    {
        return [
            'metrics' => $this->metrics,
            'connections' => $this->getConnectionStats(),
            'performance' => $this->getPerformanceStats()
        ];
    }

    protected function beginTransaction(): void
    {
        if ($this->transactionLevel === 0) {
            DB::beginTransaction();
        }
        $this->transactionLevel++;
    }

    protected function commitTransaction(): void
    {
        if ($this->transactionLevel === 1) {
            DB::commit();
        }
        $this->transactionLevel = max(0, $this->transactionLevel - 1);
    }

    protected function rollbackTransaction(): void
    {
        if ($this->transactionLevel === 1) {
            DB::rollBack();
        }
        $this->transactionLevel = max(0, $this->transactionLevel - 1);
    }

    protected function validateQuery(string $sql, array $bindings): void
    {
        // Check for dangerous operations
        $dangerousPatterns = [
            '/DROP\s+/i',
            '/TRUNCATE\s+/i',
            '/ALTER\s+/i',
            '/GRANT\s+/i',
            '/REVOKE\s+/i'
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $sql)) {
                throw new DatabaseException('Dangerous operation detected');
            }
        }

        // Validate bindings
        foreach ($bindings as $binding) {
            if (!$this->isValidBinding($binding)) {
                throw new DatabaseException('Invalid binding value');
            }
        }
    }

    protected function validateConstraint(string $table, string $constraint): bool
    {
        // Implementation depends on constraint type
        return true;
    }

    protected function isValidBinding($value): bool
    {
        // Implementation depends on binding validation rules
        return true;
    }

    protected function recordQueryMetrics(string $sql, float $duration): void
    {
        $this->incrementMetric('total_queries');
        
        if ($duration > $this->config['slow_query_threshold']) {
            $this->incrementMetric('slow_queries');
            $this->logSlowQuery($sql, $duration);
        }
    }

    protected function logSlowQuery(string $sql, float $duration): void
    {
        Log::warning('Slow query detected', [
            'sql' => $sql,
            'duration' => $duration,
            'connection' => DB::connection()->getName()
        ]);
    }

    protected function initializeMetrics(): void
    {
        $this->metrics = [
            'total_queries' => 0,
            'slow_queries' => 0,
            'successful_transactions' => 0,
            'failed_transactions' => 0,
            'bulk_inserts' => 0,
            'integrity_checks' => 0
        ];
    }

    protected function incrementMetric(string $metric): void
    {
        if (isset($this->metrics[$metric])) {
            $this->metrics[$metric]++;
        }
    }

    protected function getConnectionStats(): array
    {
        return [
            'active_connections' => DB::getConnections(),
            'default_connection' => DB::getDefaultConnection(),
            'read_write_status' => $this->getReadWriteStatus()
        ];
    }

    protected function getPerformanceStats(): array
    {
        return [
            'average_query_time' => $this->calculateAverageQueryTime(),
            'slow_query_percentage' => $this->calculateSlowQueryPercentage(),
            'transaction_success_rate' => $this->calculateTransactionSuccessRate()
        ];
    }

    protected function getReadWriteStatus(): array
    {
        return [
            'read' => DB::connection('read')->getPdo()->getAttribute(\PDO::ATTR_CONNECTION_STATUS),
            'write' => DB::connection()->getPdo()->getAttribute(\PDO::ATTR_CONNECTION_STATUS)
        ];
    }

    protected function calculateAverageQueryTime(): float
    {
        $total = $this->metrics['total_queries'];
        return $total > 0 ? Cache::get('total_query_time', 0) / $total : 0;
    }

    protected function calculateSlowQueryPercentage(): float
    {
        $total = $this->metrics['total_queries'];
        $slow = $this->metrics['slow_queries'];
        return $total > 0 ? ($slow / $total) * 100 : 0;
    }

    protected function calculateTransactionSuccessRate(): float
    {
        $total = $this->metrics['successful_transactions'] + $this->metrics['failed_transactions'];
        $successful = $this->metrics['successful_transactions'];
        return $total > 0 ? ($successful / $total) * 100 : 0;
    }

    protected function createSecurityContext(string $operation, array $data = []): array
    {
        return [
            'operation' => $operation,
            'service' => self::class,
            'data' => $data,
            'timestamp' => now(),
            'user_id' => auth()->id()
        ];
    }

    protected function handleException(\Exception $e, array $context): void
    {
        Log::error('Database operation failed', [
            'context' => $context,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        event(new DatabaseEvent('error', [
            'context' => $context,
            'error' => $e->getMessage()
        ]));
    }
}
