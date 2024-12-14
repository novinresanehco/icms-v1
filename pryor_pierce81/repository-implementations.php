<?php

namespace App\Core\Repository\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;

interface RepositoryInterface
{
    public function find(int $id): ?Model;
    public function all(): Collection;
    public function create(array $data): Model;
    public function update(int $id, array $data): Model;
    public function delete(int $id): bool;
    public function findWhere(array $criteria): Collection;
    public function findWhereFirst(array $criteria): ?Model;
    public function paginate(int $perPage = 15): mixed;
    public function with(array $relations): self;
}

namespace App\Core\Repository;

use App\Core\Repository\Contracts\RepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use App\Core\Exceptions\RepositoryException;

abstract class BaseRepository implements RepositoryInterface
{
    protected Model $model;
    protected array $with = [];
    protected int $cacheTime = 3600; // 1 hour default cache time

    public function __construct()
    {
        $this->model = app($this->getModelClass());
    }

    abstract protected function getModelClass(): string;

    public function find(int $id): ?Model
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('find', $id),
            $this->cacheTime,
            fn() => $this->model->with($this->with)->find($id)
        );
    }

    public function all(): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('all'),
            $this->cacheTime,
            fn() => $this->model->with($this->with)->get()
        );
    }

    public function create(array $data): Model
    {
        try {
            $model = $this->model->create($data);
            $this->clearCache();
            return $model;
        } catch (\Exception $e) {
            throw new RepositoryException("Failed to create model: {$e->getMessage()}");
        }
    }

    public function update(int $id, array $data): Model
    {
        try {
            $model = $this->find($id);
            if (!$model) {
                throw new RepositoryException("Model not found with ID: {$id}");
            }

            $model->update($data);
            $this->clearCache();
            return $model->fresh();
        } catch (\Exception $e) {
            throw new RepositoryException("Failed to update model: {$e->getMessage()}");
        }
    }

    public function delete(int $id): bool
    {
        try {
            $model = $this->find($id);
            if (!$model) {
                throw new RepositoryException("Model not found with ID: {$id}");
            }

            $result = $model->delete();
            $this->clearCache();
            return $result;
        } catch (\Exception $e) {
            throw new RepositoryException("Failed to delete model: {$e->getMessage()}");
        }
    }

    public function findWhere(array $criteria): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('findWhere', serialize($criteria)),
            $this->cacheTime,
            fn() => $this->model->with($this->with)->where($criteria)->get()
        );
    }

    public function findWhereFirst(array $criteria): ?Model
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey('findWhereFirst', serialize($criteria)),
            $this->cacheTime,
            fn() => $this->model->with($this->with)->where($criteria)->first()
        );
    }

    public function paginate(int $perPage = 15): mixed
    {
        return $this->model->with($this->with)->paginate($perPage);
    }

    public function with(array $relations): self
    {
        $this->with = array_merge($this->with, $relations);
        return $this;
    }

    protected function getCacheTags(): array
    {
        return [class_basename($this->model)];
    }

    protected function getCacheKey(string $method, mixed $params = null): string
    {
        $key = class_basename($this->model) . ":{$method}";
        if ($params) {
            $key .= ":{$params}";
        }
        if ($this->with) {
            $key .= ':' . implode(',', $this->with);
        }
        return $key;
    }

    protected function clearCache(): void
    {
        Cache::tags($this->getCacheTags())->flush();
    }
}
