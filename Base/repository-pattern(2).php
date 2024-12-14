<?php

namespace App\Core\Repositories;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Core\Database\Performance\DatabasePerformanceManager;
use App\Core\Contracts\CacheableInterface;
use App\Core\Traits\HasCache;
use App\Core\Exceptions\RepositoryException;

abstract class BaseRepository
{
    use HasCache;

    protected Model $model;
    protected DatabasePerformanceManager $performanceManager;
    protected array $with = [];
    protected array $searchable = [];
    protected int $cacheTtl = 3600; // 1 hour default

    public function __construct(DatabasePerformanceManager $performanceManager)
    {
        $this->performanceManager = $performanceManager;
        $this->makeModel();
    }

    abstract public function model(): string;

    public function makeModel(): Model
    {
        $model = app($this->model());
        
        if (!$model instanceof Model) {
            throw new RepositoryException("Class {$this->model()} must be instance of Illuminate\\Database\\Eloquent\\Model");
        }
        
        return $this->model = $model;
    }

    public function all(array $columns = ['*']): Collection
    {
        $cacheKey = $this->getCacheKey(__FUNCTION__, compact('columns'));
        
        return $this->remember($cacheKey, function () use ($columns) {
            $query = $this->model->with($this->with);
            $this->performanceManager->monitorQueryPerformance();
            return $query->get($columns);
        });
    }

    public function paginate(int $perPage = 15, array $columns = ['*'], array $relations = []): LengthAwarePaginator
    {
        $query = $this->model->with(array_merge($this->with, $relations));
        $this->performanceManager->monitorQueryPerformance();
        return $query->paginate($perPage, $columns);
    }

    public function find(int $id, array $columns = ['*']): ?Model
    {
        $cacheKey = $this->getCacheKey(__FUNCTION__, compact('id', 'columns'));
        
        return $this->remember($cacheKey, function () use ($id, $columns) {
            $query = $this->model->with($this->with);
            $this->performanceManager->monitorQueryPerformance();
            return $query->find($id, $columns);
        });
    }

    public function findByField(string $field, $value, array $columns = ['*']): Collection
    {
        $cacheKey = $this->getCacheKey(__FUNCTION__, compact('field', 'value', 'columns'));
        
        return $this->remember($cacheKey, function () use ($field, $value, $columns) {
            $query = $this->model->with($this->with)->where($field, '=', $value);
            $this->performanceManager->monitorQueryPerformance();
            return $query->get($columns);
        });
    }

    public function findWhere(array $where, array $columns = ['*']): Collection
    {
        $cacheKey = $this->getCacheKey(__FUNCTION__, compact('where', 'columns'));
        
        return $this->remember($cacheKey, function () use ($where, $columns) {
            $query = $this->model->with($this->with);
            
            foreach ($where as $field => $value) {
                if (is_array($value)) {
                    list($field, $operator, $search) = $value;
                    $query->where($field, $operator, $search);
                } else {
                    $query->where($field, '=', $value);
                }
            }
            
            $this->performanceManager->monitorQueryPerformance();
            return $query->get($columns);
        });
    }

    public function create(array $attributes): Model
    {
        $model = $this->model->create($attributes);
        $this->clearCache();
        return $model;
    }

    public function update(int $id, array $attributes): Model
    {
        $model = $this->find($id);
        
        if (!$model) {
            throw new RepositoryException("Model with ID {$id} not found");
        }
        
        $model->update($attributes);
        $this->clearCache();
        
        return $model;
    }

    public function delete(int $id): bool
    {
        $model = $this->find($id);
        
        if (!$model) {
            throw new RepositoryException("Model with ID {$id} not found");
        }
        
        $deleted = $model->delete();
        $this->clearCache();
        
        return $deleted;
    }

    public function search(string $term, array $columns = ['*']): Collection
    {
        if (empty($this->searchable)) {
            throw new RepositoryException('No searchable fields defined for model');
        }

        $query = $this->model->with($this->with);

        foreach ($this->searchable as $field) {
            $query->orWhere($field, 'LIKE', "%{$term}%");
        }

        $this->performanceManager->monitorQueryPerformance();
        return $query->get($columns);
    }

    public function with(array $relations): self
    {
        $this->with = $relations;
        return $this;
    }

    public function withTrashed(): self
    {
        $this->model = $this->model->withTrashed();
        return $this;
    }

    public function restore(int $id): bool
    {
        $model = $this->model->withTrashed()->find($id);
        
        if (!$model) {
            throw new RepositoryException("Model with ID {$id} not found");
        }
        
        $restored = $model->restore();
        $this->clearCache();
        
        return $restored;
    }

    protected function getCacheKey(string $function, array $args = []): string
    {
        return sprintf(
            '%s.%s.%s',
            strtolower(class_basename($this->model)),
            $function,
            md5(serialize($args))
        );
    }

    public function getModel(): Model
    {
        return $this->model;
    }

    public function setModel(Model $model): self
    {
        $this->model = $model;
        return $this;
    }

    public function setCacheTtl(int $seconds): self
    {
        $this->cacheTtl = $seconds;
        return $this;
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
