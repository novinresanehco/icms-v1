<?php

namespace App\Repositories;

use App\Exceptions\RepositoryException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

abstract class BaseRepository
{
    protected Model $model;
    protected int $cacheTTL = 3600; // 1 hour default cache
    protected array $searchableFields = [];
    protected array $filterableFields = [];
    protected array $relationships = [];

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    public function find(int $id): ?Model
    {
        return Cache::remember(
            $this->getCacheKey("id.{$id}"),
            $this->cacheTTL,
            fn() => $this->model->with($this->relationships)->find($id)
        );
    }

    public function findOrFail(int $id): Model
    {
        $model = $this->find($id);
        
        if (!$model) {
            throw new RepositoryException("Model not found with ID: {$id}");
        }

        return $model;
    }

    public function all(): Collection
    {
        return Cache::remember(
            $this->getCacheKey('all'),
            $this->cacheTTL,
            fn() => $this->model->with($this->relationships)->get()
        );
    }

    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = $this->model->with($this->relationships);
        $query = $this->applyFilters($query, $filters);
        
        return $query->paginate($perPage);
    }

    public function create(array $data): Model
    {
        $model = $this->model->create($data);
        $this->clearModelCache();
        return $model;
    }

    public function update(int $id, array $data): Model
    {
        $model = $this->findOrFail($id);
        $model->update($data);
        $this->clearModelCache();
        return $model;
    }

    public function delete(int $id): bool
    {
        $model = $this->findOrFail($id);
        $result = $model->delete();
        $this->clearModelCache();
        return $result;
    }

    public function search(string $term): Collection
    {
        if (empty($this->searchableFields)) {
            throw new RepositoryException('No searchable fields defined for this repository');
        }

        $query = $this->model->with($this->relationships);

        foreach ($this->searchableFields as $field) {
            $query->orWhere($field, 'LIKE', "%{$term}%");
        }

        return $query->get();
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        foreach ($filters as $field => $value) {
            if (in_array($field, $this->filterableFields)) {
                $query->where($field, $value);
            }
        }
        
        return $query;
    }

    protected function getCacheKey(string $key): string
    {
        return sprintf(
            '%s.%s.%s',
            strtolower(class_basename($this->model)),
            $key,
            config('app.cache_version', '1.0.0')
        );
    }

    protected function clearModelCache(): void
    {
        $modelName = strtolower(class_basename($this->model));
        Cache::tags([$modelName])->flush();
    }
}
