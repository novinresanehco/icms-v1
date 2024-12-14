<?php

namespace App\Core\Database;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\Services\{ValidationService, AuditService};
use App\Core\Interfaces\DatabaseManagerInterface;
use App\Core\Exceptions\{DatabaseException, ValidationException};

class DatabaseManager implements DatabaseManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private AuditService $audit;
    private array $config;
    private array $queryCache = [];
    private array $metrics = [];

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        AuditService $audit,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function executeQuery(string $query, array $params = [], array $options = []): mixed
    {
        return $this->security->executeSecureOperation(
            fn() => $this->processQuery($query, $params, $options),
            new SecurityContext('database.query', compact('query', 'params'))
        );
    }

    public function executeTransaction(callable $operations): mixed
    {
        return $this->security->executeSecureOperation(
            fn() => $this->processTransaction($operations),
            new SecurityContext('database.transaction', [])
        );
    }

    public function optimizeQuery(string $query): string
    {
        return $this->security->executeSecureOperation(
            fn() => $this->processQueryOptimization($query),
            new SecurityContext('database.optimize', ['query' => $query])
        );
    }

    protected function processQuery(string $query, array $params, array $options): mixed
    {
        $startTime = microtime(true);
        $cacheKey = $this->generateQueryCacheKey($query, $params);

        try {
            $this->validateQuery($query, $params);

            if ($this->shouldCache($query, $options) && $this->hasQueryCache($cacheKey)) {
                return $this->getQueryCache($cacheKey);
            }

            $result = DB::select($query, $params);
            $executionTime = microtime(true) - $startTime;

            $this->recordQueryMetrics($query, $executionTime);
            $this->auditQueryExecution($query, $params, $executionTime);

            if ($this->shouldCache($query, $options)) {
                $this->setQueryCache($cacheKey, $result);
            }

            return $result;

        } catch (\Exception $e) {
            $this->handleQueryFailure($query, $params, $e);
            throw new DatabaseException('Query execution failed: ' . $e->getMessage());
        }
    }

    protected function processTransaction(callable $operations): mixed
    {
        $startTime = microtime(true);

        try {
            DB::beginTransaction();
            
            $result = $operations();
            
            if ($this->validateTransactionResult($result)) {
                DB::commit();
                
                $executionTime = microtime(true) - $startTime;
                $this->auditTransaction($operations, $executionTime);
                
                return $result;
            }
            
            DB::rollBack();
            throw new DatabaseException('Transaction validation failed');

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleTransactionFailure($e);
            throw new DatabaseException('Transaction failed: ' . $e->getMessage());
        }
    }

    protected function processQueryOptimization(string $query): string
    {
        try {
            $explainResults = DB::select('EXPLAIN ' . $query);
            $optimizedQuery = $this->optimizeBasedOnExplain($query, $explainResults);
            
            $this->validateOptimizedQuery($optimizedQuery);
            $this->auditQueryOptimization($query, $optimizedQuery);
            
            return $optimizedQuery;

        } catch (\Exception $e) {
            $this->handleOptimizationFailure($query, $e);
            throw new DatabaseException('Query optimization failed: ' . $e->getMessage());
        }
    }

    protected function validateQuery(string $query, array $params): void
    {
        if (!$this->validator->validateSqlQuery($query)) {
            throw new ValidationException('Invalid SQL query structure');
        }

        if (!$this->validator->validateQueryParams($params)) {
            throw new ValidationException('Invalid query parameters');
        }

        if (!$this->validator->validateQueryComplexity($query)) {
            throw new ValidationException('Query complexity exceeds limits');
        }
    }

    protected function shouldCache(string $query, array $options): bool
    {
        return ($options['cache'] ?? $this->config['default_cache_enabled']) &&
               !$this->isWriteQuery($query) &&
               !$this->hasTransactionMarkers($query);
    }

    protected function generateQueryCacheKey(string $query, array $params): string
    {
        return hash('xxh3', serialize([
            'query' => $query,
            'params' => $params,
            'version' => $this->config['query_version']
        ]));
    }

    protected function hasQueryCache(string $key): bool
    {
        return isset($this->queryCache[$key]) || Cache::has($key);
    }

    protected function getQueryCache(string $key): mixed
    {
        if (isset($this->queryCache[$key])) {
            return $this->queryCache[$key];
        }

        $result = Cache::get($key);
        $this->queryCache[$key] = $result;
        return $result;
    }

    protected function setQueryCache(string $key, mixed $value): void
    {
        $this->queryCache[$key] = $value;
        Cache::put($key, $value, $this->config['query_cache_ttl']);
    }

    protected function recordQueryMetrics(string $query, float $executionTime): void
    {
        $queryHash = md5($query);
        
        $this->metrics[$queryHash] = [
            'count' => ($this->metrics[$queryHash]['count'] ?? 0) + 1,
            'total_time' => ($this->metrics[$queryHash]['total_time'] ?? 0) + $executionTime,
            'last_execution' => time()
        ];

        if ($executionTime > $this->config['slow_query_threshold']) {
            $this->audit->logSlowQuery($query, $executionTime);
        }
    }

    protected function optimizeBasedOnExplain(string $query, array $explainResults): string
    {
        $optimizations = [];

        foreach ($explainResults as $result) {
            if ($this->needsIndexOptimization($result)) {
                $optimizations[] = $this->generateIndexOptimization($result);
            }

            if ($this->needsJoinOptimization($result)) {
                $optimizations[] = $this->optimizeJoinOrder($result);
            }
        }

        return $this->applyOptimizations($query, $optimizations);
    }

    protected function validateOptimizedQuery(string $query): void
    {
        if (!$this->validator->validateOptimizedQuery($query)) {
            throw new ValidationException('Optimized query validation failed');
        }
    }

    protected function handleQueryFailure(string $query, array $params, \Exception $e): void
    {
        $this->audit->logQueryFailure($query, $params, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($this->isSecurityThreat($e)) {
            $this->security->handleSecurityThreat('query_failure', [
                'query' => $query,
                'error' => $e->getMessage()
            ]);
        }
    }

    protected function isSecurityThreat(\Exception $e): bool
    {
        return str_contains($e->getMessage(), 'SQL injection') ||
               str_contains($e->getMessage(), 'permission denied');
    }

    protected function isWriteQuery(string $query): bool
    {
        $writeCommands = ['INSERT', 'UPDATE', 'DELETE', 'DROP', 'CREATE', 'ALTER', 'TRUNCATE'];
        $firstWord = strtoupper(substr(trim($query), 0, strpos(trim($query) . ' ', ' ')));
        return in_array($firstWord, $writeCommands);
    }
}
