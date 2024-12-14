<?php

namespace App\Repositories;

use App\Interfaces\{RepositoryInterface, CacheableInterface};
use Illuminate\Database\Eloquent\{Model, Builder, Collection};
use Illuminate\Support\Facades\{DB, Cache};
use Illuminate\Pagination\LengthAwarePaginator;
use App\Services\{CacheService, SecurityService};

abstract class BaseRepository implements RepositoryInterface, CacheableInterface
{
    protected Model $model;
    protected CacheService $cache;
    protected SecurityService $security;
    protected array $searchable = [];
    protected array $filterable = [];
    protected array $sortable = [];
    protected int $cacheTTL = 3600;
    protected bool $useCache = true;

    public function __construct(
        Model $model,
        CacheService $cache,
        SecurityService $security
    ) {
        $this->model = $model;
        $this->cache = $cache;
        $this->security = $security;
    }

    public function find(int $id, array $relations = []): ?Model
    {
        $cacheKey = $this->getCacheKey(__FUNCTION__, $id, $relations);

        return $this->security->validateSecureOperation(
            fn() => $this->cache->remember(
                $cacheKey,
                fn() => $this->model->with($relations)->find($id),
                $this->cacheTTL
            ),
            ['action' => 'repository.read']
        );
    }

    public function findOrFail(int $id, array $relations = []): Model
    {
        $model = $this->find($id, $relations);

        if (!$model) {
            throw new ModelNotFoundException("Model not found with ID: {$id}");
        }

        return $model;
    }

    public function create(array $data): Model
    {
        return $this->security->validateSecureOperation(
            fn() => DB::transaction(function() use ($data) {
                $model = $this->model->create($data);
                $this->clearCache();
                return $model->fresh();
            }),
            ['action' => 'repository.create']
        );
    }

    public function update(Model $model, array $data): Model
    {
        return $this->security->validateSecureOperation(
            fn() => DB::transaction(function() use ($model, $data) {
                $model->update($data);
                $this->clearCache();
                return $model->fresh();
            }),
            ['action' => 'repository.update']
        );
    }

    public function delete(Model $model): bool
    {
        return $this->security->validateSecureOperation(
            fn() => DB::transaction(function() use ($model) {
                $result = $model->delete();
                $this->clearCache();
                return $result;
            }),
            ['action' => 'repository.delete']
        );
    }

    public function paginate(
        array $criteria = [],
        int $perPage = 15,
        array $relations = [],
        string $sortBy = 'id',
        string $sortDirection = 'desc'
    ): LengthAwarePaginator {
        $cacheKey = $this->getCacheKey(__FUNCTION__, $criteria, $perPage, $relations, $sortBy, $sortDirection);

        return $this->security->validateSecureOperation(
            fn() => $this->cache->remember(
                $cacheKey,
                fn() => $this->buildQuery($criteria, $relations, $sortBy, $sortDirection)
                    ->paginate($perPage),
                $this->cacheTTL
            ),
            ['action' => 'repository.read']
        );
    }

    public function get(
        array $criteria = [],
        array $relations = [],
        string $sortBy = 'id',
        string $sortDirection = 'desc'
    ): Collection {
        $cacheKey = $this->getCacheKey(__FUNCTION__, $criteria, $relations, $sortBy, $sortDirection);

        return $this->security->validateSecureOperation(
            fn() => $this->cache->remember(
                $cacheKey,
                fn() => $this->buildQuery($criteria, $relations, $sortBy, $sortDirection)
                    ->get(),
                $this->cacheTTL
            ),
            ['action' => 'repository.read']
        );
    }

    protected function buildQuery(
        array $criteria = [],
        array $relations = [],
        string $sortBy = 'id',
        string $sortDirection = 'desc'
    ): Builder {
        $query = $this->model->with($relations);

        foreach ($criteria as $field => $value) {
            if (in_array($field, $this->searchable) && !empty($value)) {
                $query->where($field, 'LIKE', "%{$value}%");
            } elseif (in_array($field, $this->filterable) && !empty($value)) {
                if (is_array($value)) {
                    $query->whereIn($field, $value);
                } else {
                    $query->where($field, $value);
                }
            }
        }

        if (in_array($sortBy, $this->sortable)) {
            $query->orderBy($sortBy, $sortDirection);
        }

        return $query;
    }

    protected function getCacheKey(string $method, ...$params): string
    {
        $key = strtolower(class_basename($this->model)) . 
               "." . $method . 
               "." . md5(serialize($params));

        return str_replace('\\', '.', $key);
    }

    public function clearCache(): void
    {
        if ($this->useCache) {
            $this->cache->tags([
                strtolower(class_basename($this->model))
            ])->flush();
        }
    }

    public function disableCache(): void
    {
        $this->useCache = false;
    }

    public function enableCache(): void
    {
        $this->useCache = true;
    }

    public function setCacheTTL(int $seconds): void
    {
        $this->cacheTTL = $seconds;
    }

    protected function validateSortField(string $field): void
    {
        if (!in_array($field, $this->sortable)) {
            throw new InvalidArgumentException(
                "Invalid sort field: {$field}"
            );
        }
    }

    protected function validateFilterField(string $field): void
    {
        if (!in_array($field, $this->filterable)) {
            throw new InvalidArgumentException(
                "Invalid filter field: {$field}"
            );
        }
    }

    protected function validateSearchField(string $field): void
    {
        if (!in_array($field, $this->searchable)) {
            throw new InvalidArgumentException(
                "Invalid search field: {$field}"
            );
        }
    }
}
