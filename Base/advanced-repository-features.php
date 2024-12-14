<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\RepositoryInterface;
use App\Core\Repositories\Traits\{HasTransactions, HasBulkOperations, HasSoftDeletes};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

trait HasTransactions
{
    public function transaction(callable $callback)
    {
        return $this->model->getConnection()->transaction(function () use ($callback) {
            return $callback($this);
        });
    }
}

trait HasBulkOperations
{
    public function bulkCreate(array $items): Collection
    {
        return $this->transaction(function () use ($items) {
            return collect($items)->map(function ($item) {
                return $this->create($item);
            });
        });
    }

    public function bulkUpdate(array $items, string $key = 'id'): Collection
    {
        return $this->transaction(function () use ($items, $key) {
            return collect($items)->map(function ($item) use ($key) {
                return $this->update($item[$key], $item);
            });
        });
    }

    public function bulkDelete(array $ids): bool
    {
        return $this->transaction(function () use ($ids) {
            return $this->model->whereIn('id', $ids)->delete();
        });
    }
}

trait HasSoftDeletes
{
    public function restore($id): ?Model
    {
        $model = $this->model->withTrashed()->findOrFail($id);
        $model->restore();
        return $model;
    }

    public function forceDelete($id): bool
    {
        $model = $this->model->withTrashed()->findOrFail($id);
        return $model->forceDelete();
    }

    public function getTrashed(): Collection
    {
        return $this->model->onlyTrashed()->get();
    }
}

abstract class AdvancedRepository implements RepositoryInterface
{
    use HasTransactions, HasBulkOperations, HasSoftDeletes;

    protected Model $model;
    protected array $defaultRelations = [];
    protected array $searchableFields = [];
    protected array $filterableFields = [];
    protected array $sortableFields = [];

    public function findWithRelations($id, array $relations = []): ?Model
    {
        $query = $this->model->newQuery();
        $this->loadRelations($query, $relations);
        return $query->find($id);
    }

    public function findByAttributes(array $attributes): Collection
    {
        $query = $this->model->newQuery();
        
        foreach ($attributes as $field => $value) {
            if ($this->isFilterableField($field)) {
                $query->where($field, $value);
            }
        }

        return $query->get();
    }

    public function search(string $term, array $options = []): Collection
    {
        $query = $this->model->newQuery();

        foreach ($this->searchableFields as $field) {
            $query->orWhere($field, 'LIKE', "%{$term}%");
        }

        if (!empty($options['filters'])) {
            $this->applyFilters($query, $options['filters']);
        }

        if (!empty($options['sort'])) {
            $this->applySort($query, $options['sort']);
        }

        if (!empty($options['relations'])) {
            $this->loadRelations($query, $options['relations']);
        }

        return $query->get();
    }

    protected function loadRelations($query, array $relations): void
    {
        $relationsToLoad = array_merge($this->defaultRelations, $relations);
        if (!empty($relationsToLoad)) {
            $query->with($relationsToLoad);
        }
    }

    protected function applyFilters($query, array $filters): void
    {
        foreach ($filters as $field => $value) {
            if ($this->isFilterableField($field)) {
                if (is_array($value)) {
                    $query->whereIn($field, $value);
                } else {
                    $query->where($field, $value);
                }
            }
        }
    }

    protected function applySort($query, array $sort): void
    {
        foreach ($sort as $field => $direction) {
            if ($this->isSortableField($field)) {
                $query->orderBy($field, $direction);
            }
        }
    }

    protected function isFilterableField(string $field): bool
    {
        return in_array($field, $this->filterableFields);
    }

    protected function isSortableField(string $field): bool
    {
        return in_array($field, $this->sortableFields);
    }
}
