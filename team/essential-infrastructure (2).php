<?php

namespace App\Core\Infrastructure;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\SecurityManager;
use App\Exceptions\InfrastructureException;

class DatabaseManager
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function transaction(callable $callback)
    {
        try {
            DB::beginTransaction();
            $result = $callback();
            DB::commit();
            return $result;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new InfrastructureException('Transaction failed: ' . $e->getMessage());
        }
    }

    public function query(string $table): QueryBuilder
    {
        return new QueryBuilder($table);
    }
}

class CacheManager
{
    private array $config;
    private SecurityManager $security;

    public function __construct(
        array $config,
        SecurityManager $security
    ) {
        $this->config = $config;
        $this->security = $security;
    }

    public function remember(string $key, $data, int $ttl = 3600)
    {
        return Cache::remember($key, $ttl, function() use ($data) {
            return is_callable($data) ? $data() : $data;
        });
    }

    public function forget(string $key): bool
    {
        return Cache::forget($key);
    }

    public function tags(array $tags): TaggedCache
    {
        return new TaggedCache(Cache::tags($tags));
    }
}

class QueryBuilder
{
    private string $table;
    private array $where = [];
    private array $select = ['*'];
    private ?int $limit = null;
    private array $orderBy = [];

    public function __construct(string $table)
    {
        $this->table = $table;
    }

    public function select(array $columns): self
    {
        $this->select = $columns;
        return $this;
    }

    public function where(string $column, $operator, $value = null): self
    {
        if ($value === null) {
            $value = $operator;
            $operator = '=';
        }

        $this->where[] = [$column, $operator, $value];
        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = $limit;
        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->orderBy[] = [$column, $direction];
        return $this;
    }

    public function get(): array
    {
        $query = DB::table($this->table)->select($this->select);

        foreach ($this->where as $condition) {
            $query->where(...$condition);
        }

        foreach ($this->orderBy as $order) {
            $query->orderBy(...$order);
        }

        if ($this->limit !== null) {
            $query->limit($this->limit);
        }

        return $query->get()->all();
    }

    public function first()
    {
        return $this->limit(1)->get()[0] ?? null;
    }

    public function insert(array $data): int
    {
        return DB::table($this->table)->insertGetId($data);
    }

    public function update(array $data): int
    {
        $query = DB::table($this->table);

        foreach ($this->where as $condition) {
            $query->where(...$condition);
        }

        return $query->update($data);
    }

    public function delete(): int
    {
        $query = DB::table($this->table);

        foreach ($this->where as $condition) {
            $query->where(...$condition);
        }

        return $query->delete();
    }
}

class ServiceManager
{
    private array $services = [];
    private array $singletons = [];

    public function register(string $service, callable $factory): void
    {
        $this->services[$service] = $factory;
    }

    public function singleton(string $service, callable $factory): void
    {
        $this->singletons[$service] = $factory;
    }

    public function resolve(string $service)
    {
        if (isset($this->singletons[$service])) {
            static $instances = [];
            
            if (!isset($instances[$service])) {
                $instances[$service] = ($this->singletons[$service])();
            }
            
            return $instances[$service];
        }

        if (isset($this->services[$service])) {
            return ($this->services[$service])();
        }

        throw new InfrastructureException("Service not found: $service");
    }
}

class TaggedCache
{
    private $cache;

    public function __construct($cache)
    {
        $this->cache = $cache;
    }

    public function remember(string $key, $data, int $ttl = 3600)
    {
        return $this->cache->remember($key, $ttl, function() use ($data) {
            return is_callable($data) ? $data() : $data;
        });
    }

    public function forget(string $key): bool
    {
        return $this->cache->forget($key);
    }

    public function flush(): bool
    {
        return $this->cache->flush();
    }
}
