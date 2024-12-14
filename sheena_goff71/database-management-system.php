<?php

namespace App\Core\Database;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\Interfaces\{DatabaseManagerInterface, QueryInterface};
use App\Core\Exceptions\{DatabaseException, SecurityException};

class DatabaseManager implements DatabaseManagerInterface
{
    private SecurityManager $security;
    private QueryBuilder $queryBuilder;
    private QueryOptimizer $optimizer;
    private ValidationService $validator;
    private array $config;

    public function __construct(
        SecurityManager $security,
        QueryBuilder $queryBuilder,
        QueryOptimizer $optimizer,
        ValidationService $validator,
        array $config
    ) {
        $this->security = $security;
        $this->queryBuilder = $queryBuilder;
        $this->optimizer = $optimizer;
        $this->validator = $validator;
        $this->config = $config;
    }

    public function executeQuery(string $query, array $params = [], array $options = []): QueryResult
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processQuery($query, $params, $options),
            ['action' => 'execute_query']
        );
    }

    public function executeTransaction(callable $operations): mixed
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->processTransaction($operations),
            ['action' => 'execute_transaction']
        );
    }

    protected function processQuery(string $query, array $params, array $options): QueryResult
    {
        $queryId = $this->generateQueryId();
        $this->validateQuery($query, $params);

        try {
            $optimizedQuery = $this->optimizeQuery($query, $params);
            $startTime = microtime(true);

            if ($this->shouldUseCache($query, $options)) {
                $result = $this->executeWithCache($optimizedQuery, $params, $options);
            } else {
                $result = $this->executeDirectQuery($optimizedQuery, $params);
            }

            $this->logQueryMetrics($queryId, $query, microtime(true) - $startTime);
            return new QueryResult($result);

        } catch (\Exception $e) {
            $this->handleQueryFailure($e, $queryId, $query);
            throw new DatabaseException('Query execution failed', 0, $e);
        }
    }

    protected function processTransaction(callable $operations): mixed
    {
        $transactionId = $this->generateTransactionId();

        try {
            DB::beginTransaction();
            
            $startTime = microtime(true);
            $this->setTransactionIsolationLevel();
            
            $result = $operations();
            
            $this->validateTransactionState();
            DB::commit();
            
            $this->logTransactionMetrics($transactionId, microtime(true) - $startTime);
            
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleTransactionFailure($e, $transactionId);
            throw new DatabaseException('Transaction failed', 0, $e);
        }
    }

    protected function validateQuery(string $query, array $params): void
    {
        if (!$this->validator->validateQuery($query)) {
            throw new DatabaseException('Invalid query format');
        }

        if (!$this->validator->validateQueryParams($params)) {
            throw new DatabaseException('Invalid query parameters');
        }

        if ($this->containsUnauthorizedOperations($query)) {
            throw new SecurityException('Unauthorized query operations');
        }
    }

    protected function optimizeQuery(string $query, array $params): string
    {
        $optimizedQuery = $this->optimizer->optimize($query, $params);
        
        if ($this->exceedsComplexityThreshold($optimizedQuery)) {
            throw new DatabaseException('Query complexity exceeds threshold');
        }
        
        return $optimizedQuery;
    }

    protected function executeWithCache(string $query, array $params, array $options): mixed
    {
        $cacheKey = $this->generateCacheKey($query, $params);
        $ttl = $options['cache_ttl'] ?? $this->config['default_cache_ttl'];

        return Cache::tags(['queries'])
            ->remember($cacheKey, $ttl, fn() => $this->executeDirectQuery($query, $params));
    }

    protected function executeDirectQuery(string $query, array $params): mixed
    {
        $statement = DB::prepare($query);
        
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, $this->getParamType($value));
        }
        
        return $statement->execute();
    }

    protected function setTransactionIsolationLevel(): void
    {
        $level = $this->config['transaction_isolation_level'];
        DB::statement("SET TRANSACTION ISOLATION LEVEL {$level}");
    }

    protected function validateTransactionState(): void
    {
        if (!$this->validator->validateTransactionState()) {
            throw new DatabaseException('Invalid transaction state');
        }
    }

    protected function shouldUseCache(string $query, array $options): bool
    {
        return isset($options['use_cache']) &&
               $options['use_cache'] &&
               $this->isCacheableQuery($query);
    }

    protected function isCacheableQuery(string $query): bool
    {
        return stripos($query, 'SELECT') === 0 &&
               !$this->containsNonCacheableKeywords($query);
    }

    protected function containsUnauthorizedOperations(string $query): bool
    {
        foreach ($this->config['forbidden_operations'] as $operation) {
            if (stripos($query, $operation) !== false) {
                return true;
            }
        }
        return false;
    }

    protected function containsNonCacheableKeywords(string $query): bool
    {
        foreach ($this->config['non_cacheable_keywords'] as $keyword) {
            if (stripos($query, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }

    protected function exceedsComplexityThreshold(string $query): bool
    {
        return $this->optimizer->calculateComplexity($query) > 
               $this->config['max_query_complexity'];
    }

    protected function getParamType($value): int
    {
        return match(gettype($value)) {
            'integer' => \PDO::PARAM_INT,
            'boolean' => \PDO::PARAM_BOOL,
            'NULL' => \PDO::PARAM_NULL,
            default => \PDO::PARAM_STR
        };
    }

    protected function generateQueryId(): string
    {
        return uniqid('qry_', true);
    }

    protected function generateTransactionId(): string
    {
        return uniqid('txn_', true);
    }

    protected function generateCacheKey(string $query, array $params): string
    {
        return 'query:' . md5($query . serialize($params));
    }

    protected function logQueryMetrics(string $queryId, string $query, float $duration): void
    {
        $this->updateQueryStats($duration);

        if ($duration > $this->config['slow_query_threshold']) {
            Log::warning('Slow query detected', [
                'query_id' => $queryId,
                'query' => $query,
                'duration' => $duration
            ]);
        }
    }

    protected function logTransactionMetrics(string $transactionId, float $duration): void
    {
        $this->updateTransactionStats($duration);

        if ($duration > $this->config['slow_transaction_threshold']) {
            Log::warning('Slow transaction detected', [
                'transaction_id' => $transactionId,
                'duration' => $duration
            ]);
        }
    }

    protected function updateQueryStats(float $duration): void
    {
        $key = 'db:query_stats:' . date('YmdH');
        
        Cache::tags(['database', 'stats'])->remember($key, 3600, function() {
            return ['count' => 0, 'total_time' => 0];
        });

        Cache::tags(['database', 'stats'])->increment("{$key}:count");
        Cache::tags(['database', 'stats'])->increment("{$key}:total_time", $duration);
    }

    protected function updateTransactionStats(float $duration): void
    {
        $key = 'db:transaction_stats:' . date('YmdH');
        
        Cache::tags(['database', 'stats'])->remember($key, 3600, function() {
            return ['count' => 0, 'total_time' => 0];
        });

        Cache::tags(['database', 'stats'])->increment("{$key}:count");
        Cache::tags(['database', 'stats'])->increment("{$key}:total_time", $duration);
    }

    protected function handleQueryFailure(\Exception $e, string $queryId, string $query): void
    {
        Log::error('Query execution failed', [
            'query_id' => $queryId,
            'query' => $query,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function handleTransactionFailure(\Exception $e, string $transactionId): void
    {
        Log::error('Transaction failed', [
            'transaction_id' => $transactionId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
