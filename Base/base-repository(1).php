<?php

namespace App\Repositories;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Repositories\Contracts\BaseRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

abstract class BaseRepository implements BaseRepositoryInterface
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

    public function create(array $data): ?Model
    {
        try {
            DB::beginTransaction();
            
            $model = $this->model->create($data);
            
            if (!empty($this->relationships)) {
                $this->handleRelationships($model, $data);
            }
            
            DB::commit();
            $this->clearModelCache();
            
            return $model;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create ' . class_basename($this->model) . ': ' . $e->getMessage());
            return null;
        }
    }

    public function update(int $id, array $data): bool
    {
        try {
            DB::beginTransaction();
            
            $model = $this->model->findOrFail($id);
            $model->update($data);
            
            if (!empty($this->relationships)) {
                $this->handleRelationships($model, $data);
            }
            
            DB::commit();
            $this->clearModelCache();
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to update ' . class_basename($this->model) . ': ' . $e->getMessage());
            return false;
        }
    }

    public function delete(int $id): bool
    {
        try {
            DB::beginTransaction();
            
            $model = $this->model->findOrFail($id);
            $model->delete();
            
            DB::commit();
            $this->clearModelCache();
            
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete ' . class_basename($this->model) . ': ' . $e->getMessage());
            return false;
        }
    }

    public function find(int $id, array $relations = []): ?Model
    {
        try {
            return Cache::remember(
                $this->getCacheKey("find.{$id}", $relations),
                $this->cacheTTL,
                fn() => $this->model->with($relations)->find($id)
            );
        } catch (\Exception $e) {
            Log::error('Failed to find ' . class_basename($this->model) . ': ' . $e->getMessage());
            return null;
        }
    }

    public function findByField(string $field, $value, array $relations = []): ?Model
    {
        try {
            return Cache::remember(
                $this->getCacheKey("findByField.{$field}.{$value}", $relations),
                $this->cacheTTL,
                fn() => $this->model->with($relations)->where($field, $value)->first()
            );
        } catch (\Exception $e) {
            Log::error('Failed to find ' . class_basename($this->model) . ' by field: ' . $e->getMessage());
            return null;
        }
    }

    public function paginate(array $filters = [], int $perPage = 15, array $relations = []): LengthAwarePaginator
    {
        try {
            $query = $this->model->query();
            
            if (!empty($relations)) {
                $query->with($relations);
            }
            
            $this->applyFilters($query, $filters);
            
            return $query->paginate($perPage);
        } catch (\Exception $e) {
            Log::error('Failed to paginate ' . class_basename($this->model) . ': ' . $e->getMessage());
            return new LengthAwarePaginator([], 0, $perPage);
        }
    }

    public function search(string $term, array $fields = [], array $relations = []): Collection
    {
        try {
            $searchFields = !empty($fields) ? $fields : $this->searchableFields;
            
            $query = $this->model->query();
            
            if (!empty($relations)) {
                $query->with($relations);
            }
            
            $query->where(function (Builder $q) use ($searchFields, $term) {
                foreach ($searchFields as $field) {
                    $q->orWhere($field, 'LIKE', "%{$term}%");
                }
            });
            
            return $query->get();
        } catch (\Exception $e) {
            Log::error('Failed to search ' . class_basename($this->model) . ': ' . $e->getMessage());
            return new Collection();
        }
    }

    protected function handleRelationships(Model $model, array $data): void
    {
        foreach ($this->relationships as $relation => $type) {
            if (isset($data[$relation])) {
                switch ($type) {
                    case 'sync':
                        $model->{$relation}()->sync($data[$relation]);
                        break;
                    case 'attach':
                        $model->{$relation}()->attach($data[$relation]);
                        break;
                    case 'update':
                        $model->{$relation}()->update($data[$relation]);
                        break;
                }
            }
        }
    }

    protected function applyFilters(Builder $query, array $filters): void
    {
        foreach ($filters as $field => $value) {
            if (in_array($field, $this->filterableFields)) {
                if (is_array($value)) {
                    $query->whereIn($field, $value);
                } else {
                    $query->where($field, $value);
                }
            }
        }
    }

    protected function getCacheKey(string $key, array $relations = []): string
    {
        $modelName = class_basename($this->model);
        $relationKey = empty($relations) ? '' : '.with.' . implode('.', $relations);
        return "model.{$modelName}.{$key}{$relationKey}";
    }

    protected function clearModelCache(): void
    {
        $modelName = class_basename($this->model);
        Cache::tags([$modelName])->flush();
    }
}
