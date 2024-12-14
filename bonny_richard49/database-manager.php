<?php

namespace App\Core\Database;

use Illuminate\Support\Facades\DB;
use App\Core\Interfaces\DatabaseInterface;
use App\Core\Services\{CacheManager, SecurityManager};
use App\Core\Exceptions\DatabaseException;

class DatabaseManager implements DatabaseInterface
{
    private CacheManager $cache;
    private SecurityManager $security;
    private array $config;
    private array $queryLog = [];
    private array $transactions = [];
    
    public function __construct(
        CacheManager $cache,
        SecurityManager $security,
        array $config
    ) {
        $this->cache = $cache;
        $this->security = $security;
        $this->config = $config;
    }

    public function transaction(callable $callback)
    {
        $transactionId = $this->beginTransaction();
        
        try {
            $result = $callback($this);
            $this->commitTransaction($transactionId);
            return $result;
        } catch (\Exception $e) {
            $this->rollbackTransaction($transactionId);
            throw new DatabaseException('Transaction failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function query(string $sql, array $bindings = [], array $options = []): array
    {
        try {
            $this->security->validateOperation('database.query', [
                'sql' => $sql,
                'options' => $options
            ]);

            $cacheKey = $this->generateCacheKey($sql, $bindings);
            $cacheTTL = $options['cache_ttl'] ?? $this->config['default_cache_ttl'] ?? 3600;

            return $this->cache->remember($cacheKey, $cacheTTL, function() use ($sql, $bindings) {
                $this->logQuery($sql, $bindings);
                return DB::select($sql, $bindings);
            });
        } catch (\Exception $e) {
            throw new DatabaseException('Query execution failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function insert(string $table, array $data, array $options = []): int
    {
        try {
            $this->security->validateOperation('database.insert', [
                'table' => $table,
                'data' => $data
            ]);

            $this->validateTable($table);
            $this->validateData($data, $table);

            $id = DB::table($table)->insertGetId(
                $this->prepareData($data),
                $options['id_column'] ?? 'id'
            );

            $this->invalidateTableCache($table);
            return $id;
        } catch (\Exception $e) {
            throw new DatabaseException('Insert operation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function update(string $table, array $criteria, array $data, array $options = []): int
    {
        try {
            $this->security->validateOperation('database.update', [
                'table' => $table,
                'criteria' => $criteria,
                'data' => $data
            ]);

            $this->validateTable($table);
            $this->validateData($data, $table);
            $this->validateCriteria($criteria);

            $affected = DB::table($table)
                ->where($criteria)
                ->update($this->prepareData($data));

            $this->invalidateTableCache($table);
            return $affected;
        } catch (\Exception $e) {
            throw new DatabaseException('Update operation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function delete(string $table, array $criteria, array $options = []): int
    {
        try {
            $this->security->validateOperation('database.delete', [
                'table' => $table,
                'criteria' => $criteria
            ]);

            $this->validateTable($table);
            $this->validateCriteria($criteria);

            if ($options['soft_delete'] ?? $this->config['soft_delete'] ?? true) {
                $affected = $this->softDelete($table, $criteria);
            } else {
                $affected = DB::table($table)->where($criteria)->delete();
            }

            $this->invalidateTableCache($table);
            return $affected;
        } catch (\Exception $e) {
            throw new DatabaseException('Delete operation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function beginTransaction(): string
    {
        try {
            DB::beginTransaction();
            $transactionId = $this->generateTransactionId();
            $this->transactions[$transactionId] = true;
            return $transactionId;
        } catch (\Exception $e) {
            throw new DatabaseException('Failed to start transaction: ' . $e->getMessage(), 0, $e);
        }
    }

    public function commitTransaction(string $transactionId): void
    {
        try {
            if (!isset($this->transactions[$transactionId])) {
                throw new DatabaseException('Invalid transaction ID');
            }

            DB::commit();
            unset($this->transactions[$transactionId]);
        } catch (\Exception $e) {
            throw new DatabaseException('Failed to commit transaction: ' . $e->getMessage(), 0, $e);
        }
    }

    public function rollbackTransaction(string $transactionId): void
    {
        try {
            if (!isset($this->transactions[$transactionId])) {
                throw new DatabaseException('Invalid transaction ID');
            }

            DB::rollBack();
            unset($this->transactions[$transactionId]);
        } catch (\Exception $e) {
            throw new DatabaseException('Failed to rollback transaction: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function validateTable(string $table): void
    {
        if (!in_array($table, $this->config['allowed_tables'])) {
            throw new DatabaseException('Invalid table name: ' . $table);
        }
    }

    protected function validateData(array $data, string $table): void
    {
        $allowedColumns = $this->config['table_schemas'][$table] ?? [];
        
        foreach (array_keys($data) as $column) {
            if (!in_array($column, $allowedColumns)) {
                throw new DatabaseException("Invalid column for table {$table}: {$column}");
            }
        }
    }

    protected function validateCriteria(array $criteria): void
    {
        if (empty($criteria)) {
            throw new DatabaseException('Empty criteria not allowed');
        }
    }

    protected function prepareData(array $data): array
    {
        return array_map(function($value) {
            if (is_array($value)) {
                return json_encode($value);
            }
            return $value;
        }, $data);
    }

    protected function softDelete(string $table, array $criteria): int
    {
        return DB::table($table)
            ->where($criteria)
            ->update([
                'deleted_at' => time(),
                'updated_at' => time()
            ]);
    }

    protected function generateTransactionId(): string
    {
        return uniqid('txn_', true);
    }

    protected function generateCacheKey(string $sql, array $bindings): string
    {
        return 'query:' . md5($sql . json_encode($bindings));
    }

    protected function invalidateTableCache(string $table): void
    {
        $this->cache->tags(['database', "table:{$table}"])->flush();
    }

    protected function logQuery(string $sql, array $bindings): void
    {
        if ($this->config['query_logging'] ?? false) {
            $this->queryLog[] = [
                'sql' => $sql,
                'bindings' => $bindings,
                'time' => microtime(true)
            ];
        }
    }

    public function __destruct()
    {
        foreach (array_keys($this->transactions) as $transactionId) {
            try {
                $this->rollbackTransaction($transactionId);
            } catch (\Exception $e) {
                // Silently handle rollback failures in destructor
            }
        }
    }
}
