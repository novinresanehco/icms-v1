<?php

namespace App\Core\Repositories;

use App\Core\Contracts\RepositoryInterface;
use App\Core\Cache\CacheManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

abstract class BaseRepository implements RepositoryInterface
{
    protected Model $model;
    protected CacheManager $cache;
    protected array $with = [];
    protected array $searchable = [];
    protected int $cacheTtl = 3600;

    public function find(int $id): ?Model
    {
        return $this->cache->remember(
            $this->getCacheKey(__FUNCTION__, $id),
            $this->cacheTtl,
            fn() => $this->model->with($this->with)->find($id)
        );
    }

    public function findOrFail(int $id): Model
    {
        return $this->cache->remember(
            $this->getCacheKey(__FUNCTION__, $id),
            $this->cacheTtl,
            fn() => $this->model->with($this->with)->findOrFail($id)
        );
    }

    public function all(): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey(__FUNCTION__),
            $this->cacheTtl,
            fn() => $this->model->with($this->with)->get()
        );
    }

    public function paginate(int $perPage = 15, array $columns = ['*']): LengthAwarePaginator
    {
        $key = $this->getCacheKey(__FUNCTION__, [$perPage, implode(',', $columns), request('page', 1)]);
        
        return $this->cache->remember(
            $key,
            $this->cacheTtl,
            fn() => $this->model->with($this->with)->paginate($perPage, $columns)
        );
    }

    public function create(array $data): Model
    {
        $model = $this->model->create($data);
        $this->clearCache();
        return $model;
    }

    public function update(int $id, array $data): Model
    {
        $model = $this->findOrFail($id);
        $model->update($data);
        $this->clearCache();
        return $model->fresh();
    }

    public function delete(int $id): bool
    {
        $result = $this->findOrFail($id)->delete();
        $this->clearCache();
        return $result;
    }

    public function findBy(string $field, mixed $value): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey(__FUNCTION__, [$field, $value]),
            $this->cacheTtl,
            fn() => $this->model->where($field, $value)->with($this->with)->get()
        );
    }

    public function findOneBy(string $field, mixed $value): ?Model
    {
        return $this->cache->remember(
            $this->getCacheKey(__FUNCTION__, [$field, $value]),
            $this->cacheTtl,
            fn() => $this->model->where($field, $value)->with($this->with)->first()
        );
    }

    public function search(string $query): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey(__FUNCTION__, $query),
            $this->cacheTtl,
            function() use ($query) {
                return $this->model->where(function($q) use ($query) {
                    foreach ($this->searchable as $field) {
                        $q->orWhere($field, 'LIKE', "%{$query}%");
                    }
                })->with($this->with)->get();
            }
        );
    }

    public function updateOrCreate(array $attributes, array $values): Model
    {
        $model = $this->model->updateOrCreate($attributes, $values);
        $this->clearCache();
        return $model;
    }

    public function findWhere(array $conditions): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey(__FUNCTION__, serialize($conditions)),
            $this->cacheTtl,
            fn() => $this->model->where($conditions)->with($this->with)->get()
        );
    }

    public function count(): int
    {
        return $this->cache->remember(
            $this->getCacheKey(__FUNCTION__),
            $this->cacheTtl,
            fn() => $this->model->count()
        );
    }

    protected function getCacheKey(string $function, mixed $params = null): string
    {
        $key = strtolower(class_basename($this->model)) . ":{$function}";
        return $params ? "{$key}:" . md5(serialize($params)) : $key;
    }

    protected function clearCache(): void
    {
        $key = strtolower(class_basename($this->model)) . ':*';
        $this->cache->deletePattern($key);
    }

    public function beginTransaction(): void
    {
        $this->model->getConnection()->beginTransaction();
    }

    public function commit(): void
    {
        $this->model->getConnection()->commit();
    }

    public function rollBack(): void
    {
        $this->model->getConnection()->rollBack();
    }
}
