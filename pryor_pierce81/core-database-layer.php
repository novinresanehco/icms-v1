<?php

namespace App\Core\Database;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Exceptions\{DatabaseException, SecurityException};
use Illuminate\Support\Facades\DB;

class DatabaseManager implements DatabaseInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private QueryBuilder $queryBuilder;
    private ValidationService $validator;
    private array $config;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        QueryBuilder $queryBuilder,
        ValidationService $validator,
        array $config
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->queryBuilder = $queryBuilder;
        $this->validator = $validator;
        $this->config = $config;
    }

    public function executeQuery(string $sql, array $params = []): QueryResult
    {
        return $this->security->executeCriticalOperation(
            new DatabaseOperation($sql, $params, $this->validator),
            SecurityContext::system()
        );
    }

    public function transaction(callable $operations): mixed
    {
        DB::beginTransaction();
        
        try {
            $result = $operations();
            DB::commit();
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new DatabaseException(
                'Transaction failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public function select(string $table, array $columns = ['*']): QueryBuilder
    {
        $this->validateTable($table);
        $this->validateColumns($columns);

        return $this->queryBuilder
            ->select($columns)
            ->from($table);
    }

    public function insert(string $table, array $data): int
    {
        $this->validateTable($table);
        $this->validateData($data);

        return $this->transaction(function() use ($table, $data) {
            return DB::table($table)->insertGetId($data);
        });
    }

    public function update(string $table, array $data, array $conditions): int
    {
        $this->validateTable($table);
        $this->validateData($data);
        $this->validateConditions($conditions);

        return $this->transaction(function() use ($table, $data, $conditions) {
            return DB::table($table)
                ->where($conditions)
                ->update($data);
        });
    }

    public function delete(string $table, array $conditions): int
    {
        $this->validateTable($table);
        $this->validateConditions($conditions);

        return $this->transaction(function() use ($table, $conditions) {
            return DB::table($table)
                ->where($conditions)
                ->delete();
        });
    }

    private function validateTable(string $table): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            throw new DatabaseException('Invalid table name');
        }

        if (!in_array($table, $this->config['allowed_tables'])) {
            throw new SecurityException('Unauthorized table access');
        }
    }

    private function validateColumns(array $columns): void
    {
        foreach ($columns as $column) {
            if ($column !== '*' && !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
                throw new DatabaseException('Invalid column name');
            }
        }
    }

    private function validateData(array $data): void
    {
        foreach ($data as $column => $value) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
                throw new DatabaseException('Invalid column name');
            }
        }

        if (!$this->validator->validateData($data)) {
            throw new ValidationException('Invalid data format');
        }
    }

    private function validateConditions(array $conditions): void
    {
        foreach ($conditions as $column => $value) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
                throw new DatabaseException('Invalid column name');
            }
        }

        if (!$this->validator->validateConditions($conditions)) {
            throw new ValidationException('Invalid conditions format');
        }
    }
}

class QueryBuilder
{
    private array $select = [];
    private ?string $from = null;
    private array $joins = [];
    private array $where = [];
    private array $orderBy = [];
    private ?int $limit = null;
    private int $offset = 0;
    private array $params = [];

    public function select(array $columns = ['*']): self
    {
        $this->select = $columns;
        return $this;
    }

    public function from(string $table): self
    {
        $this->from = $table;
        return $this;
    }

    public function join(string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = compact('table', 'first', 'operator', 'second');
        return $this;
    }

    public function where(string $column, string $operator, $value): self
    {
        $this->where[] = compact('column', 'operator', 'value');
        $this->params[] = $value;
        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->orderBy[] = compact('column', 'direction');
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = $offset;
        return $this;
    }

    public function toSql(): string
    {
        $sql = 'SELECT ' . implode(', ', $this->select);
        $sql .= ' FROM ' . $this->from;

        foreach ($this->joins as $join) {
            $sql .= " JOIN {$join['table']} ON {$join['first']} {$join['operator']} {$join['second']}";
        }

        if (!empty($this->where)) {
            $conditions = array_map(function($condition) {
                return "{$condition['column']} {$condition['operator']} ?";
            }, $this->where);
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        if (!empty($this->orderBy)) {
            $orders = array_map(function($order) {
                return "{$order['column']} {$order['direction']}";
            }, $this->orderBy);
            $sql .= ' ORDER BY ' . implode(', ', $orders);
        }

        if ($this->limit !== null) {
            $sql .= " LIMIT {$this->limit}";
        }

        if ($this->offset > 0) {
            $sql .= " OFFSET {$this->offset}";
        }

        return $sql;
    }

    public function getParams(): array
    {
        return $this->params;
    }

    public function execute(): QueryResult
    {
        return DB::select($this->toSql(), $this->getParams());
    }
}
