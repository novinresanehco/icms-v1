<?php

namespace App\Core\Repositories;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Core\Exceptions\RepositoryException;

trait QueryFilterable
{
    protected function applyFilters(Builder $query, array $filters): Builder
    {
        foreach ($filters as $field => $value) {
            if (method_exists($this, $method = 'filter' . studly_case($field))) {
                $this->$method($query, $value);
            } elseif (in_array($field, $this->filterable ?? [])) {
                $query->where($field, $value);
            }
        }
        return $query;
    }

    protected function applySorting(Builder $query, ?string $sortField, string $direction = 'asc'): Builder
    {
        if ($sortField && in_array($sortField, $this->sortable ?? [])) {
            $query->orderBy($sortField, $direction);
        }
        return $query;
    }
}

trait Cacheable
{
    protected function getCacheKey(string $key, array $params = []): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->cachePrefix ?? class_basename($this),
            $key,
            md5(serialize($params))
        );
    }

    protected function remember(string $key, \Closure $callback, ?int $ttl = null): mixed
    {
        return Cache::tags($this->getCacheTags())->remember(
            $key,
            $ttl ?? $this->cacheTTL ?? 3600,
            $callback
        );
    }

    protected function getCacheTags(): array
    {
        return [$this->cachePrefix ?? class_basename($this)];
    }

    protected function clearCache(): void
    {
        Cache::tags($this->getCacheTags())->flush();
    }
}

abstract class BaseRepository
{
    use QueryFilterable, Cacheable;

    protected array $filterable = [];
    protected array $sortable = [];
    protected array $with = [];
    protected ?int $cacheTTL = 3600;
    protected ?string $cachePrefix = null;

    public function __construct(protected Model $model)
    {
    }

    public function find(int $id, array $relations = []): ?Model
    {
        return $this->remember(
            $this->getCacheKey(__FUNCTION__, [$id, $relations]),
            fn() => $this->query()->with($relations)->find($id)
        );
    }

    public function findOrFail(int $id, array $relations = []): Model
    {
        $model = $this->find($id, $relations);
        if (!$model) {
            throw new RepositoryException("Model not found with ID: {$id}");
        }
        return $model;
    }

    public function all(array $relations = []): Collection
    {
        return $this->remember(
            $this->getCacheKey(__FUNCTION__, $relations),
            fn() => $this->query()->with($relations)->get()
        );
    }

    public function create(array $data): Model
    {
        return DB::transaction(function () use ($data) {
            $model = $this->model->create($data);
            $this->clearCache();
            return $model;
        });
    }

    public function update(Model $model, array $data): bool
    {
        return DB::transaction(function () use ($model, $data) {
            $updated = $model->update($data);
            if ($updated) {
                $this->clearCache();
            }
            return $updated;
        });
    }

    public function delete(Model $model): bool
    {
        return DB::transaction(function () use ($model) {
            $deleted = $model->delete();
            if ($deleted) {
                $this->clearCache();
            }
            return $deleted;
        });
    }

    public function findByField(string $field, mixed $value, array $relations = []): Collection
    {
        return $this->remember(
            $this->getCacheKey(__FUNCTION__, [$field, $value, $relations]),
            fn() => $this->query()->with($relations)->where($field, $value)->get()
        );
    }

    public function findWhere(array $conditions, array $relations = []): Collection
    {
        return $this->remember(
            $this->getCacheKey(__FUNCTION__, [$conditions, $relations]),
            fn() => $this->query()->with($relations)->where($conditions)->get()
        );
    }

    public function paginate(
        int $perPage = 15,
        array $filters = [],
        ?string $sortField = null,
        string $direction = 'asc'
    ): LengthAwarePaginator {
        $query = $this->query()->with($this->with);
        $query = $this->applyFilters($query, $filters);
        $query = $this->applySorting($query, $sortField, $direction);
        
        return $query->paginate($perPage);
    }

    protected function query(): Builder
    {
        return $this->model->newQuery();
    }
}

class ContentRepository extends BaseRepository
{
    protected array $filterable = ['status', 'category_id', 'author_id'];
    protected array $sortable = ['created_at', 'updated_at', 'title'];
    protected array $with = ['category', 'author', 'tags'];

    public function findPublished(array $relations = []): Collection
    {
        return $this->remember(
            $this->getCacheKey(__FUNCTION__, $relations),
            fn() => $this->query()
                ->with($relations)
                ->where('status', 'published')
                ->where('published_at', '<=', now())
                ->orderByDesc('published_at')
                ->get()
        );
    }

    public function findBySlug(string $slug, array $relations = []): ?Model
    {
        return $this->remember(
            $this->getCacheKey(__FUNCTION__, [$slug, $relations]),
            fn() => $this->query()
                ->with($relations)
                ->where('slug', $slug)
                ->where('status', 'published')
                ->where('published_at', '<=', now())
                ->first()
        );
    }

    public function search(string $term, array $relations = []): Collection
    {
        return $this->query()
            ->with($relations)
            ->where('status', 'published')
            ->where(function ($query) use ($term) {
                $query->where('title', 'like', "%{$term}%")
                    ->orWhere('content', 'like', "%{$term}%");
            })
            ->orderByDesc('published_at')
            ->get();
    }

    protected function filterStatus(Builder $query, string $status): void
    {
        $query->where('status', $status);
    }
}

class CategoryRepository extends BaseRepository
{
    protected array $filterable = ['active'];
    protected array $sortable = ['name', 'sort_order'];
    protected array $with = ['parent', 'children'];

    public function findBySlug(string $slug, array $relations = []): ?Model
    {
        return $this->remember(
            $this->getCacheKey(__FUNCTION__, [$slug, $relations]),
            fn() => $this->query()
                ->with($relations)
                ->where('slug', $slug)
                ->where('active', true)
                ->first()
        );
    }

    public function findActive(array $relations = []): Collection
    {
        return $this->remember(
            $this->getCacheKey(__FUNCTION__, $relations),
            fn() => $this->query()
                ->with($relations)
                ->where('active', true)
                ->orderBy('sort_order')
                ->get()
        );
    }

    public function reorder(array $items): bool
    {
        return DB::transaction(function () use ($items) {
            foreach ($items as $order => $id) {
                $this->query()->where('id', $id)->update(['sort_order' => $order]);
            }
            $this->clearCache();
            return true;
        });
    }
}

class TagRepository extends BaseRepository
{
    protected array $filterable = ['type'];
    protected array $sortable = ['name', 'usage_count'];

    public function findPopular(int $limit = 10): Collection
    {
        return $this->remember(
            $this->getCacheKey(__FUNCTION__, [$limit]),
            fn() => $this->query()
                ->orderByDesc('usage_count')
                ->limit($limit)
                ->get()
        );
    }

    public function incrementUsage(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $updated = $this->query()->where('id', $id)->increment('usage_count');
            if ($updated) {
                $this->clearCache();
            }
            return (bool) $updated;
        });
    }
}
