<?php

namespace App\Core\Repositories\Decorators;

use App\Core\Repositories\Contracts\RepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

abstract class CacheableRepository implements RepositoryInterface
{
    protected RepositoryInterface $repository;
    protected string $cachePrefix;
    protected int $ttl = 3600; // 1 hour default TTL

    public function __construct(RepositoryInterface $repository)
    {
        $this->repository = $repository;
        $this->cachePrefix = $this->getCachePrefix();
    }

    protected function getCachePrefix(): string
    {
        return strtolower(class_basename($this->repository)) . ':';
    }

    protected function getCacheKey(string $key, array $params = []): string
    {
        return $this->cachePrefix . $key . ':' . md5(serialize($params));
    }

    protected function rememberCache(string $key, array $params, \Closure $callback)
    {
        return Cache::remember(
            $this->getCacheKey($key, $params),
            $this->ttl,
            $callback
        );
    }

    protected function forgetCache(string $key, array $params = []): void
    {
        Cache::forget($this->getCacheKey($key, $params));
    }

    protected function flushCache(): void
    {
        $keys = Cache::get($this->cachePrefix . 'keys', []);
        foreach ($keys as $key) {
            Cache::forget($key);
        }
        Cache::forget($this->cachePrefix . 'keys');
    }

    public function all(array $columns = ['*']): Collection
    {
        return $this->rememberCache('all', compact('columns'), function () use ($columns) {
            return $this->repository->all($columns);
        });
    }

    public function find(int $id, array $columns = ['*']): ?Model
    {
        return $this->rememberCache('find', compact('id', 'columns'), function () use ($id, $columns) {
            return $this->repository->find($id, $columns);
        });
    }

    public function create(array $data): Model
    {
        $model = $this->repository->create($data);
        $this->flushCache();
        return $model;
    }

    public function update(int $id, array $data): Model
    {
        $model = $this->repository->update($id, $data);
        $this->flushCache();
        return $model;
    }

    public function delete(int $id): bool
    {
        $result = $this->repository->delete($id);
        $this->flushCache();
        return $result;
    }

    // Implement other RepositoryInterface methods...
}
