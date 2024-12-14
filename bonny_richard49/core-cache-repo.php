<?php

namespace App\Core\Data;

use App\Core\Contracts\{CacheableInterface, PersistenceInterface};
use App\Core\Security\SecurityManager;
use Illuminate\Support\Facades\{Cache, DB};
use App\Core\Exceptions\{DataException, CacheException};

class CacheManager implements CacheableInterface
{
    private array $config;
    private SecurityManager $security;

    public function __construct(SecurityManager $security, array $config)
    {
        $this->security = $security;
        $this->config = $config;
    }

    public function remember(string $key, callable $callback, int $ttl = null): mixed
    {
        $ttl ??= $this->config['default_ttl'] ?? 3600;

        try {
            return Cache::tags($this->getSafeTags($key))
                ->remember($this->getSecureKey($key), $ttl, function() use ($callback) {
                    return $this->security->executeSecureOperation(
                        $callback,
                        ['action' => 'cache_retrieve']
                    );
                });
        } catch (\Exception $e) {
            throw new CacheException("Cache operation failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function invalidate(string $key): void
    {
        try {
            Cache::tags($this->getSafeTags($key))->forget($this->getSecureKey($key));
        } catch (\Exception $e) {
            throw new CacheException("Cache invalidation failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function invalidatePattern(string $pattern): void
    {
        try {
            Cache::tags($this->getSafeTags($pattern))->flush();
        } catch (\Exception $e) {
            throw new CacheException("Pattern invalidation failed: {$e->getMessage()}", 0, $e);
        }
    }

    protected function getSecureKey(string $key): string
    {
        return hash_hmac('sha256', $key, $this->config['cache_key']);
    }

    protected function getSafeTags(string $key): array
    {
        $prefix = $this->config['cache_prefix'] ?? 'app';
        return [$prefix, $prefix . ':' . substr(md5($key), 0, 12)];
    }
}

class DataRepository implements PersistenceInterface
{
    private CacheManager $cache;
    private SecurityManager $security;
    private string $table;
    private array $config;

    public function __construct(
        CacheManager $cache,
        SecurityManager $security,
        string $table,
        array $config = []
    ) {
        $this->cache = $cache;
        $this->security = $security;
        $this->table = $table;
        $this->config = $config;
    }

    public function find(int $id): ?array
    {
        return $this->cache->remember(
            $this->getCacheKey('find', $id),
            fn() => $this->security->executeSecureOperation(
                fn() => DB::table($this->table)->find($id),
                ['action' => 'find', 'id' => $id]
            )
        );
    }

    public function store(array $data): array
    {
        return $this->security->executeSecureOperation(
            function() use ($data) {
                $result = DB::transaction(function() use ($data) {
                    $id = DB::table($this->table)->insertGetId($this->prepareData($data));
                    return $this->find($id);
                });

                $this->clearCachePattern('list');
                return $result;
            },
            ['action' => 'store']
        );
    }

    public function update(int $id, array $data): array
    {
        return $this->security->executeSecureOperation(
            function() use ($id, $data) {
                $result = DB::transaction(function() use ($id, $data) {
                    DB::table($this->table)
                        ->where('id', $id)
                        ->update($this->prepareData($data));
                    return $this->find($id);
                });

                $this->invalidateRelatedCache($id);
                return $result;
            },
            ['action' => 'update', 'id' => $id]
        );
    }

    public function delete(int $id): bool
    {
        return $this->security->executeSecureOperation(
            function() use ($id) {
                $result = DB::transaction(function() use ($id) {
                    return DB::table($this->table)->delete($id);
                });

                $this->invalidateRelatedCache($id);
                return $result;
            },
            ['action' => 'delete', 'id' => $id]
        );
    }

    protected function prepareData(array $data): array
    {
        $data['updated_at'] = now();
        
        if (!isset($data['created_at'])) {
            $data['created_at'] = $data['updated_at'];
        }

        return $this->security->encryptSensitiveData($data);
    }

    protected function getCacheKey(string $operation, ...$params): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->table,
            $operation,
            implode(':', array_map('strval', $params))
        );
    }

    protected function invalidateRelatedCache(int $id): void
    {
        $this->cache->invalidate($this->getCacheKey('find', $id));
        $this->cache->invalidatePattern($this->table . ':list*');
    }

    protected function clearCachePattern(string $pattern): void
    {
        $this->cache->invalidatePattern($this->table . ':' . $pattern . '*');
    }
}

class QueryBuilder
{
    private DataRepository $repository;
    private array $conditions = [];
    private array $order = [];
    private ?int $limit = null;
    private int $offset = 0;

    public function __construct(DataRepository $repository)
    {
        $this->repository = $repository;
    }

    public function where(string $column, string $operator, $value): self
    {
        $this->conditions[] = [$column, $operator, $value];
        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->order[] = [$column, $direction];
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

    public function get(): array
    {
        $query = DB::table($this->repository->getTable());

        foreach ($this->conditions as [$column, $operator, $value]) {
            $query->where($column, $operator, $value);
        }

        foreach ($this->order as [$column, $direction]) {
            $query->orderBy($column, $direction);
        }

        if ($this->limit !== null) {
            $query->limit($this->limit);
        }

        if ($this->offset > 0) {
            $query->offset($this->offset);
        }

        return $query->get()->toArray();
    }

    public function count(): int
    {
        $query = DB::table($this->repository->getTable());

        foreach ($this->conditions as [$column, $operator, $value]) {
            $query->where($column, $operator, $value);
        }

        return $query->count();
    }
}
