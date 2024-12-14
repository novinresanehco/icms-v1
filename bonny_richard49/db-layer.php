<?php

namespace App\Core\Database;

use Illuminate\Support\Facades\{DB, Cache};
use Illuminate\Database\Query\Builder;

abstract class BaseRepository
{
    protected string $table;
    protected array $fillable;
    protected array $hidden;
    protected array $casts;
    protected CacheManager $cache;
    protected ValidationService $validator;
    protected array $searchable = [];

    public function __construct(
        CacheManager $cache,
        ValidationService $validator
    ) {
        $this->cache = $cache;
        $this->validator = $validator;
    }

    public function find(int $id): ?Model
    {
        return $this->cache->remember(
            $this->getCacheKey($id),
            fn() => $this->findById($id)
        );
    }

    public function create(array $data): Model
    {
        $this->validator->validate($data, $this->getValidationRules());
        
        DB::beginTransaction();
        try {
            $id = DB::table($this->table)->insertGetId(
                $this->prepareData($data)
            );
            
            $model = $this->findById($id);
            $this->cache->invalidate($this->table);
            
            DB::commit();
            return $model;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function update(int $id, array $data): Model
    {
        $this->validator->validate($data, $this->getValidationRules($id));
        
        DB::beginTransaction();
        try {
            DB::table($this->table)
                ->where('id', $id)
                ->update($this->prepareData($data));
                
            $model = $this->findById($id);
            $this->cache->invalidate([$this->table, $this->getCacheKey($id)]);
            
            DB::commit();
            return $model;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function delete(int $id): bool
    {
        DB::beginTransaction();
        try {
            $deleted = DB::table($this->table)->delete($id);
            
            if ($deleted) {
                $this->cache->invalidate([
                    $this->table,
                    $this->getCacheKey($id)
                ]);
            }
            
            DB::commit();
            return $deleted > 0;
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function findBy(array $criteria, array $orderBy = []): Collection
    {
        $query = DB::table($this->table);
        
        foreach ($criteria as $field => $value) {
            if (is_array($value)) {
                $query->whereIn($field, $value);
            } else {
                $query->where($field, $value);
            }
        }

        foreach ($orderBy as $field => $direction) {
            $query->orderBy($field, $direction);
        }

        return $this->cache->remember(
            $this->getCacheKey(serialize($criteria + $orderBy)),
            fn() => $this->processResults($query->get())
        );
    }

    public function search(string $term, array $fields = []): Collection
    {
        $fields = empty($fields) ? $this->searchable : $fields;
        
        $query = DB::table($this->table);
        
        foreach ($fields as $field) {
            $query->orWhere($field, 'like', "%{$term}%");
        }

        return $this->processResults($query->get());
    }

    protected function findById(int $id): ?Model
    {
        $data = DB::table($this->table)->find($id);
        return $data ? $this->createModel((array)$data) : null;
    }

    protected function prepareData(array $data): array
    {
        $prepared = array_intersect_key($data, array_flip($this->fillable));
        
        foreach ($this->casts as $field => $type) {
            if (isset($prepared[$field])) {
                $prepared[$field] = $this->castValue($prepared[$field], $type);
            }
        }

        return $prepared;
    }

    protected function processResults($results): Collection
    {
        return collect($results)->map(
            fn($data) => $this->createModel((array)$data)
        );
    }

    protected function createModel(array $data): Model
    {
        $data = array_diff_key($data, array_flip($this->hidden));
        
        foreach ($this->casts as $field => $type) {
            if (isset($data[$field])) {
                $data[$field] = $this->castValue($data[$field], $type);
            }
        }

        return new Model($data);
    }

    protected function castValue($value, string $type)
    {
        return match($type) {
            'int' => (int)$value,
            'float' => (float)$value,
            'bool' => (bool)$value,
            'array' => json_decode($value, true),
            'datetime' => new \DateTime($value),
            default => $value
        };
    }

    protected function getValidationRules(?int $id = null): array
    {
        return [];
    }

    protected function getCacheKey(mixed $identifier): string
    {
        return $this->table . '.' . md5(serialize($identifier));
    }
}

class DatabaseManager
{
    private QueryBuilder $builder;
    private TransactionManager $transactions;
    private QueryCache $cache;
    private array $config;

    public function __construct(
        QueryBuilder $builder,
        TransactionManager $transactions,
        QueryCache $cache,
        array $config
    ) {
        $this->builder = $builder;
        $this->transactions = $transactions;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function transaction(callable $callback): mixed
    {
        return $this->transactions->execute($callback);
    }

    public function query(): Builder
    {
        return $this->builder->newQuery();
    }

    public function raw(string $query, array $bindings = []): mixed
    {
        if ($this->isReadQuery($query)) {
            return $this->cache->remember(
                $this->cache->generateKey($query, $bindings),
                fn() => DB::raw($query, $bindings)
            );
        }
        
        return DB::raw($query, $bindings);
    }

    private function isReadQuery(string $query): bool
    {
        return !preg_match('/^(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE)/i', $query);
    }
}

class TransactionManager
{
    private int $level = 0;

    public function execute(callable $callback): mixed
    {
        if ($this->level === 0) {
            DB::beginTransaction();
        }
        
        $this->level++;
        
        try {
            $result = $callback();
            
            $this->level--;
            
            if ($this->level === 0) {
                DB::commit();
            }
            
            return $result;
            
        } catch (\Exception $e) {
            $this->level--;
            
            if ($this->level === 0) {
                DB::rollBack();
            }
            
            throw $e;
        }
    }
}

class QueryCache
{
    private CacheManager $cache;
    private array $config;

    public function remember(string $key, callable $callback): mixed
    {
        return $this->cache->remember($key, $callback);
    }

    public function generateKey(string $query, array $bindings): string
    {
        return 'query.' . md5($query . serialize($bindings));
    }
}

class Model
{
    private array $attributes;

    public function __construct(array $attributes)
    {
        $this->attributes = $attributes;
    }

    public function __get(string $name)
    {
        return $this->attributes[$name] ?? null;
    }

    public function toArray(): array
    {
        return $this->attributes;
    }
}

class Collection
{
    private array $items;

    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    public function map(callable $callback): self
    {
        return new self(array_map($callback, $this->items));
    }

    public function filter(callable $callback): self
    {
        return new self(array_filter($this->items, $callback));
    }

    public function toArray(): array
    {
        return array_map(
            fn($item) => $item instanceof Model ? $item->toArray() : $item,
            $this->items
        );
    }
}
