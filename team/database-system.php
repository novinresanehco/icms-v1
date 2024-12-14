<?php

namespace App\Core\Database;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\{DB, Log};

class DatabaseManager implements DatabaseManagerInterface
{
    private SecurityManagerInterface $security;
    private CacheManager $cache;
    private QueryValidator $validator;
    private TransactionManager $transactions;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        CacheManager $cache,
        QueryValidator $validator,
        TransactionManager $transactions,
        array $config
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->transactions = $transactions;
        $this->config = $config;
    }

    public function executeQuery(Query $query, SecurityContext $context): QueryResult
    {
        return $this->security->executeCriticalOperation(
            function() use ($query, $context) {
                $this->validateQuery($query);
                $this->validatePermissions($query, $context);

                if ($this->canCacheQuery($query)) {
                    return $this->executeWithCache($query);
                }

                return $this->executeWithTransaction($query);
            },
            $context
        );
    }

    public function beginTransaction(): Transaction
    {
        return $this->transactions->begin(
            $this->security->getCurrentContext()
        );
    }

    protected function validateQuery(Query $query): void
    {
        if (!$this->validator->validateQuery($query)) {
            throw new QueryValidationException('Invalid query structure');
        }

        if (!$this->validator->validateParameters($query->getParameters())) {
            throw new QueryValidationException('Invalid query parameters');
        }

        $this->validateQueryComplexity($query);
    }

    protected function validatePermissions(Query $query, SecurityContext $context): void
    {
        $tables = $this->extractTables($query);
        
        foreach ($tables as $table) {
            if (!$this->security->hasTablePermission($context, $table, $query->getType())) {
                throw new DatabaseSecurityException("Insufficient permissions for table: $table");
            }
        }
    }

    protected function executeWithCache(Query $query): QueryResult
    {
        $cacheKey = $this->generateCacheKey($query);

        return $this->cache->remember(
            $cacheKey,
            fn() => $this->executeWithTransaction($query),
            $this->getCacheTtl($query)
        );
    }

    protected function executeWithTransaction(Query $query): QueryResult
    {
        $transaction = $this->transactions->current() ?? $this->beginTransaction();

        try {
            $startTime = microtime(true);
            $result = $transaction->execute($query);
            $this->recordMetrics($query, $startTime);

            if (!$this->transactions->current()) {
                $transaction->commit();
            }

            return $result;

        } catch (\Throwable $e) {
            if (!$this->transactions->current()) {
                $transaction->rollback();
            }
            throw $e;
        }
    }

    protected function validateQueryComplexity(Query $query): void
    {
        $complexity = $this->analyzer->calculateComplexity($query);
        $limit = $this->config['max_complexity'] ?? 100;

        if ($complexity > $limit) {
            throw new QueryComplexityException(
                "Query complexity ($complexity) exceeds limit ($limit)"
            );
        }
    }

    protected function canCacheQuery(Query $query): bool
    {
        return $query->isCacheable() &&
               $query->getType() === QueryType::SELECT &&
               !$this->transactions->current();
    }

    protected function generateCacheKey(Query $query): string
    {
        return 'query:' . hash('sha256', serialize([
            'sql' => $query->getSQL(),
            'parameters' => $query->getParameters(),
            'context' => $this->security->getCurrentContext()
        ]));
    }

    protected function getCacheTtl(Query $query): int
    {
        return $query->getCacheTtl() 
            ?? $this->config['cache_ttl'] 
            ?? 3600;
    }

    protected function recordMetrics(Query $query, float $startTime): void
    {
        $duration = microtime(true) - $startTime;

        $this->metrics->record('query_execution', [
            'type' => $query->getType(),
            'tables' => $this->extractTables($query),
            'duration' => $duration,
            'complexity' => $this->analyzer->calculateComplexity($query)
        ]);

        if ($duration > ($this->config['slow_query_threshold'] ?? 1.0)) {
            $this->logSlowQuery($query, $duration);
        }
    }

    protected function extractTables(Query $query): array
    {
        return $this->analyzer->extractTables($query);
    }

    protected function logSlowQuery(Query $query, float $duration): void
    {
        Log::warning('Slow query detected', [
            'query' => $query->getSQL(),
            'duration' => $duration,
            'tables' => $this->extractTables($query),
            'context' => $this->security->getCurrentContext()
        ]);
    }
}
