<?php

namespace App\Core\Database;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Events\DatabaseEvent;
use App\Core\Exceptions\{DatabaseException, SecurityException};
use Illuminate\Support\Facades\{DB, Log};

class DatabaseManager implements DatabaseInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private array $activeTransactions = [];
    private array $queryLog = [];
    private array $config;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        array $config = []
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->config = array_merge([
            'max_transaction_duration' => 30,
            'max_retries' => 3,
            'query_timeout' => 10,
            'deadlock_retry_wait' => 100,
            'query_logging' => true
        ], $config);
    }

    public function transaction(callable $callback, int $attempts = null): mixed
    {
        return $this->security->executeCriticalOperation(
            function() use ($callback, $attempts) {
                $transactionId = $this->beginTransaction();
                
                try {
                    $result = $this->executeTransactionWithRetry(
                        $callback,
                        $attempts ?? $this->config['max_retries']
                    );
                    
                    $this->commitTransaction($transactionId);
                    return $result;
                    
                } catch (\Exception $e) {
                    $this->rollbackTransaction($transactionId);
                    throw new DatabaseException(
                        'Transaction failed: ' . $e->getMessage(),
                        0,
                        $e
                    );
                } finally {
                    $this->cleanupTransaction($transactionId);
                }
            },
            ['operation' => 'database_transaction']
        );
    }

    public function query(string $sql, array $bindings = [], array $options = []): mixed
    {
        return $this->security->executeCriticalOperation(
            function() use ($sql, $bindings, $options) {
                // Validate and sanitize query
                $this->validateQuery($sql, $bindings);
                
                // Set query timeout
                $timeout = $options['timeout'] ?? $this->config['query_timeout'];
                DB::statement("SET statement_timeout TO " . ($timeout * 1000));
                
                try {
                    // Execute query with monitoring
                    $startTime = microtime(true);
                    $result = $this->executeQuery($sql, $bindings);
                    $duration = microtime(true) - $startTime;
                    
                    // Log query metrics
                    $this->logQuery($sql, $bindings, $duration);
                    
                    return $result;
                    
                } catch (\Exception $e) {
                    $this->handleQueryError($e, $sql, $bindings);
                    throw $e;
                } finally {
                    // Reset query timeout
                    DB::statement("SET statement_timeout TO DEFAULT");
                }
            },
            ['operation' => 'database_query']
        );
    }

    protected function beginTransaction(): string
    {
        $transactionId = $this->generateTransactionId();
        
        DB::beginTransaction();
        
        $this->activeTransactions[$transactionId] = [
            'start_time' => microtime(true),
            'queries' => []
        ];
        
        event(new DatabaseEvent('transaction_begin', $transactionId));
        
        return $transactionId;
    }

    protected function commitTransaction(string $transactionId): void
    {
        $this->validateTransactionDuration($transactionId);
        
        DB::commit();
        
        event(new DatabaseEvent('transaction_commit', $transactionId, [
            'duration' => microtime(true) - $this->activeTransactions[$transactionId]['start_time'],
            'queries' => count($this->activeTransactions[$transactionId]['queries'])
        ]));
    }

    protected function rollbackTransaction(string $transactionId): void
    {
        DB::rollBack();
        
        event(new DatabaseEvent('transaction_rollback', $transactionId, [
            'duration' => microtime(true) - $this->activeTransactions[$transactionId]['start_time'],
            'queries' => count($this->activeTransactions[$transactionId]['queries'])
        ]));
    }

    protected function executeTransactionWithRetry(callable $callback, int $attempts): mixed
    {
        $attempt = 1;
        
        while (true) {
            try {
                return $callback();
            } catch (\Exception $e) {
                if ($this->shouldRetryTransaction($e, $attempt, $attempts)) {
                    $attempt++;
                    usleep($this->calculateRetryDelay($attempt));
                    continue;
                }
                throw $e;
            }
        }
    }

    protected function shouldRetryTransaction(\Exception $e, int $attempt, int $maxAttempts): bool
    {
        return $attempt < $maxAttempts && 
               ($e instanceof \PDOException && $this->isDeadlockError($e));
    }

    protected function calculateRetryDelay(int $attempt): int
    {
        return $this->config['deadlock_retry_wait'] * pow(2, $attempt - 1);
    }

    protected function validateQuery(string $sql, array $bindings): void
    {
        // Check for dangerous SQL patterns
        $dangerousPatterns = [
            '/UNION\s+SELECT/i',
            '/INTO\s+OUTFILE/i',
            '/LOAD_FILE/i',
            '/INFORMATION_SCHEMA/i'
        ];

        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $sql)) {
                throw new SecurityException('Potentially dangerous SQL pattern detected');
            }
        }

        // Validate bindings
        foreach ($bindings as $value) {
            if ($this->isUnsafeBinding($value)) {
                throw new SecurityException('Unsafe SQL binding detected');
            }
        }
    }

    protected function executeQuery(string $sql, array $bindings): mixed
    {
        $startTime = microtime(true);
        
        try {
            if (stripos($sql, 'select') === 0) {
                return DB::select($sql, $bindings);
            } elseif (stripos($sql, 'insert') === 0) {
                return DB::insert($sql, $bindings);
            } elseif (stripos($sql, 'update') === 0) {
                return DB::update($sql, $bindings);
            } elseif (stripos($sql, 'delete') === 0) {
                return DB::delete($sql, $bindings);
            } else {
                return DB::statement($sql, $bindings);
            }
        } finally {
            $duration = microtime(true) - $startTime;
            $this->monitorQueryPerformance($sql, $duration);
        }
    }

    protected function logQuery(string $sql, array $bindings, float $duration): void
    {
        if (!$this->config['query_logging']) {
            return;
        }

        $this->queryLog[] = [
            'sql' => $sql,
            'bindings' => $bindings,
            'duration' => $duration,
            'timestamp' => microtime(true)
        ];

        event(new DatabaseEvent('query_executed', null, [
            'sql' => $sql,
            'duration' => $duration
        ]));
    }

    protected function monitorQueryPerformance(string $sql, float $duration): void
    {
        // Log slow queries
        if ($duration > 1.0) {
            Log::warning('Slow query detected', [
                'sql' => $sql,
                'duration' => $duration
            ]);
        }

        // Update performance metrics
        $this->cache->remember('query_metrics', 3600, function() {
            return [
                'total_queries' => 0,
                'total_duration' => 0,
                'slow_queries' => 0
            ];
        });

        $metrics = $this->cache->get('query_metrics');
        $metrics['total_queries']++;
        $metrics['total_duration'] += $duration;
        if ($duration > 1.0) {
            $metrics['slow_queries']++;
        }
        
        $this->cache->put('query_metrics', $metrics, 3600);
    }

    protected function handleQueryError(\Exception $e, string $sql, array $bindings): void
    {
        Log::error('Query execution failed', [
            'sql' => $sql,
            'bindings' => $bindings,
            'error' => $e->getMessage()
        ]);

        event(new DatabaseEvent('query_failed', null, [
            'sql' => $sql,
            'error' => $e->getMessage()
        ]));
    }

    protected function generateTransactionId(): string
    {
        return uniqid('txn_', true);
    }

    protected function validateTransactionDuration(string $transactionId): void
    {
        $duration = microtime(true) - $this->activeTransactions[$transactionId]['start_time'];
        
        if ($duration > $this->config['max_transaction_duration']) {
            throw new DatabaseException('Transaction duration exceeded maximum allowed time');
        }
    }

    protected function cleanupTransaction(string $transactionId): void
    {
        unset($this->activeTransactions[$transactionId]);
    }

    protected function isDeadlockError(\PDOException $e): bool
    {
        return stripos($e->getMessage(), 'deadlock') !== false;
    }

    protected function isUnsafeBinding($value): bool
    {
        if (is_string($value)) {
            return preg_match('/[\x00-\x1F\x7F]/', $value) || 
                   strlen($value) > 10000;
        }
        return false;
    }
}
