<?php

namespace App\Core\Repositories;

use App\Core\Contracts\Repositories\AdvancedRepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use App\Core\Exceptions\RepositoryException;

interface AdvancedRepositoryInterface
{
    public function findWithCriteria(array $criteria): Collection;
    public function paginateWithCriteria(array $criteria, int $perPage = 15): LengthAwarePaginator;
    public function updateById(int $id, array $data): bool;
    public function deleteById(int $id): bool;
    public function findWithRelations(int $id, array $relations): ?Model;
    public function restore(int $id): bool;
    public function forceDelete(int $id): bool;
}

abstract class AdvancedRepository extends AbstractRepository implements AdvancedRepositoryInterface
{
    protected array $defaultRelations = [];
    protected array $searchableFields = [];
    protected array $filterableFields = [];
    protected array $sortableFields = [];

    public function findWithCriteria(array $criteria): Collection
    {
        $query = $this->newQuery();
        
        $this->applyCriteria($query, $criteria);
        
        return $query->get();
    }

    public function paginateWithCriteria(array $criteria, int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->newQuery();
        
        $this->applyCriteria($query, $criteria);
        
        return $query->paginate($perPage);
    }

    public function updateById(int $id, array $data): bool
    {
        return DB::transaction(function() use ($id, $data) {
            $model = $this->findOrFail($id);
            return $this->update($model, $data);
        });
    }

    public function deleteById(int $id): bool
    {
        return DB::transaction(function() use ($id) {
            $model = $this->findOrFail($id);
            return $this->delete($model);
        });
    }

    public function findWithRelations(int $id, array $relations): ?Model
    {
        return $this->newQuery()
            ->with($relations)
            ->find($id);
    }

    public function restore(int $id): bool
    {
        return DB::transaction(function() use ($id) {
            $model = $this->model->withTrashed()->findOrFail($id);
            return $model->restore();
        });
    }

    public function forceDelete(int $id): bool
    {
        return DB::transaction(function() use ($id) {
            $model = $this->model->withTrashed()->findOrFail($id);
            return $model->forceDelete();
        });
    }

    protected function applyCriteria($query, array $criteria): void
    {
        // Apply relations
        if (!empty($criteria['with'])) {
            $query->with($criteria['with']);
        }

        // Apply where conditions
        if (!empty($criteria['where'])) {
            foreach ($criteria['where'] as $field => $value) {
                if (in_array($field, $this->filterableFields)) {
                    $query->where($field, $value);
                }
            }
        }

        // Apply search
        if (!empty($criteria['search']) && !empty($this->searchableFields)) {
            $query->where(function($q) use ($criteria) {
                foreach ($this->searchableFields as $field) {
                    $q->orWhere($field, 'LIKE', "%{$criteria['search']}%");
                }
            });
        }

        // Apply sorting
        if (!empty($criteria['sort']) && in_array($criteria['sort'], $this->sortableFields)) {
            $direction = $criteria['direction'] ?? 'asc';
            $query->orderBy($criteria['sort'], $direction);
        }

        // Apply date range
        if (!empty($criteria['dateFrom'])) {
            $query->where('created_at', '>=', $criteria['dateFrom']);
        }
        if (!empty($criteria['dateTo'])) {
            $query->where('created_at', '<=', $criteria['dateTo']);
        }
    }

    protected function findOrFail(int $id): Model
    {
        $model = $this->find($id);
        
        if (!$model) {
            throw new RepositoryException("Model not found with ID: {$id}");
        }
        
        return $model;
    }
}

class CategoryRepository extends AdvancedRepository
{
    protected array $searchableFields = ['name', 'description'];
    protected array $filterableFields = ['status', 'parent_id'];
    protected array $sortableFields = ['name', 'created_at', 'updated_at'];
    protected array $defaultRelations = ['parent', 'children'];

    protected function model(): string
    {
        return Category::class;
    }

    public function findActiveWithChildren(): Collection
    {
        return $this->newQuery()
            ->with('children')
            ->where('status', 'active')
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->get();
    }

    public function reorder(array $items): bool
    {
        return DB::transaction(function() use ($items) {
            foreach ($items as $order => $id) {
                $this->updateById($id, ['sort_order' => $order]);
            }
            return true;
        });
    }
}

class TagRepository extends AdvancedRepository
{
    protected array $searchableFields = ['name'];
    protected array $filterableFields = ['type'];
    protected array $sortableFields = ['name', 'created_at', 'usage_count'];

    protected function model(): string
    {
        return Tag::class;
    }

    public function incrementUsage(int $id): bool
    {
        return DB::transaction(function() use ($id) {
            return $this->model->where('id', $id)
                ->increment('usage_count');
        });
    }

    public function findPopular(int $limit = 10): Collection
    {
        return $this->newQuery()
            ->orderBy('usage_count', 'desc')
            ->limit($limit)
            ->get();
    }

    public function findOrCreateByName(string $name): Model
    {
        return DB::transaction(function() use ($name) {
            return $this->model->firstOrCreate(
                ['name' => $name],
                ['slug' => str_slug($name)]
            );
        });
    }
}
