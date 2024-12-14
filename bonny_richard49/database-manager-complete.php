<?php

namespace App\Core\Database;

use App\Core\Interfaces\{
    DatabaseManagerInterface,
    CacheManagerInterface
};
use Illuminate\Support\Facades\{DB, Schema};
use Psr\Log\LoggerInterface;

class DatabaseManager implements DatabaseManagerInterface
{
    private CacheManagerInterface $cache;
    private LoggerInterface $logger;
    private array $config;
    private array $metrics;

    private const CACHE_TTL = 3600;
    private const QUERY_TIMEOUT = 30;
    private const MAX_ATTEMPTS = 3;
    private const DEADLOCK_RETRY_DELAY = 1;
    private const METRICS_KEY = 'db:metrics';

    public function __construct(
        CacheManagerInterface $cache,
        LoggerInterface $logger
    ) {
        $this->cache = $cache;
        $this->logger = $logger;
        $this->config = config('database');
        $this->metrics = [];
    }

    public function executeTransaction(callable $operation)
    {
        try {
            DB::beginTransaction();

            $startTime = microtime(true);
            $result = $operation();
            $duration = microtime(true) - $startTime;

            $this->recordMetric('transaction', $duration);
            
            DB::commit();
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            
            $this->handleDatabaseError('transaction', $e);
            
            if ($this->isDeadlock($e) && $this->shouldRetry($e)) {
                return $this->retryTransaction($operation);
            }
            
            throw $e;
        }
    }

    public function executeQuery(string $query, array $params = [])
    {
        try {
            $startTime = microtime(true);
            
            $result = DB::select($query, $params);
            
            $duration = microtime(true) - $startTime;
            $this->recordMetric('query', $duration);
            
            if ($duration > $this->config['slow_query_threshold']) {
                $this->logSlowQuery($query, $params, $duration);
            }
            
            return $result;
        } catch (\Exception $e) {
            $this->handleDatabaseError('query', $e, [
                'query' => $query,
                'params' => $params
            ]);
            throw $e;
        }
    }

    public function executeCachedQuery(string $query, array $params = [], ?int $ttl = null)
    {
        $cacheKey = $this->generateCacheKey($query, $params);
        
        return $this->cache->remember(
            $cacheKey,
            $ttl ?? self::CACHE_TTL,
            fn() => $this->executeQuery($query, $params)
        );
    }

    public function optimizeTable(string $table): void
    {
        try {
            DB::statement("OPTIMIZE TABLE {$table}");
            $this->recordMetric('optimize', 0, $table);
        } catch (\Exception $e) {
            $this->handleDatabaseError('optimize', $e, ['table' => $table]);
            throw $e;
        }
    }

    public function createBackup(string $table): string
    {
        try {
            $backupTable = $table . '_backup_' . time();
            
            Schema::create($backupTable, function($table) use ($table) {
                DB::statement("CREATE TABLE {$backupTable} LIKE {$table}");
                DB::statement("INSERT INTO {$backupTable} SELECT * FROM {$table}");
            });

            $this->recordMetric('backup', 0, $table);
            
            return $backupTable;
        } catch (\Exception $e) {
            $this->handleDatabaseError('backup', $e, ['table' => $table]);
            throw $e;
        }
    }

    public function restoreFromBackup(string $table, string $backupTable): void
    {
        try {
            $this->executeTransaction(function() use ($table, $backupTable) {
                DB::statement("DROP TABLE IF EXISTS {$table}");
                DB::statement("CREATE TABLE {$table} LIKE {$backupTable}");
                DB::statement("INSERT INTO {$table} SELECT * FROM {$backupTable}");
            });

            $this->recordMetric('restore', 0, $table);
        } catch (\Exception $e) {
            $this->handleDatabaseError('restore', $e, [
                'table' => $table,
                'backup' => $backupTable
            ]);
            throw $e;
        }
    }

    public function getTableStatus(string $table): array
    {
        try {
            $status = DB::select("SHOW TABLE STATUS LIKE ?", [$table])[0];
            
            return [
                'rows' => $status->Rows,
                'size' => $status->Data_length + $status->Index_length,
                'auto_increment' => $status->Auto_increment,
                'engine' => $status->Engine,
                'collation' => $status->Collation,
                'updated_at' => $status->Update_time
            ];
        } catch (\Exception $e) {
            $this->handleDatabaseError('status', $e, ['table' => $table]);
            throw $e;
        }
    }

    protected function retryTransaction(callable $operation, int $attempt = 1): mixed
    {
        if ($attempt > self::MAX_ATTEMPTS) {
            throw new DatabaseException('Max retry attempts exceeded');
        }

        usleep($attempt * self::DEADLOCK_RETRY_DELAY * 1000000);
        
        try {
            return $this->executeTransaction($operation);
        } catch (\Exception $e) {
            if ($this->isDeadlock($e) && $this->shouldRetry($e)) {
                return $this->retryTransaction($operation, $attempt + 1);
            }
            throw $e;
        }
    }

    protected function isDeadlock(\Exception $e): bool
    {
        return strpos($e->getMessage(), 'Deadlock found') !== false;
    }

    protected function shouldRetry(\Exception $e): bool
    {
        return $this->isDeadlock($e) || 
               $e instanceof \PDOException;
    }

    protected function generateCacheKey(string $query, array $params): string
    {
        return 'query:' . md5($query . serialize($params));
    }

    protected function recordMetric(string $operation, float $duration, ?string $table = null): void
    {
        try {
            $metrics = $this->getMetrics();
            
            $metrics['operations'][$operation] = ($metrics['operations'][$operation] ?? 0) + 1;
            $metrics['duration'][$operation] = ($metrics['duration'][$operation] ?? 0) + $duration;
            
            if ($table) {
                $metrics['tables'][$table][$operation] = ($metrics['tables'][$table][$operation] ?? 0) + 1;
            }
            
            $this->saveMetrics($metrics);
        } catch (\Exception $e) {
            $this->logger->error('Failed to record database metric', [
                'operation' => $operation,
                'error' => $e->getMessage()
            ]);
        }
    }

    protected function getMetrics(): array
    {
        try {
            return $this->cache->get(self::METRICS_KEY, []);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get database metrics', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    protected function saveMetrics(array $metrics): void
    {
        try {
            $this->cache->put(self::METRICS_KEY, $metrics, self::CACHE_TTL);
        } catch (\Exception $e) {
            $this->logger->error('Failed to save database metrics', [
                'error' => $e->getMessage()
            ]);
        }
    }

    protected function logSlowQuery(string $query, array $params, float $duration): void
    {
        DB::table('slow_queries')->insert([
            'query' => $query,
            'params' => json_encode($params),
            'duration' => $duration,
            'created_at' => time()
        ]);

        $this->logger->warning('Slow query detected', [
            'query' => $query,
            'params' => $params,
            'duration' => $duration
        ]);
    }

    protected function handleDatabaseError(string $operation, \Exception $e, array $context = []): void
    {
        $context = array_merge($context, [
            'operation' => $operation,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->logger->error('Database operation failed', $context);

        if ($operation === 'query' && isset($context['query'])) {
            DB::table('failed_queries')->insert([
                'query' => $context['query'],
                'params' => json_encode($context['params'] ?? []),
                'error' => $e->getMessage(),
                'created_at' => time()
            ]);
        }
    }
}
