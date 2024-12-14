<?php

namespace App\Core\Contracts\Repositories;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface EntityRepositoryInterface
{
    public function find(int $id): ?Model;
    public function findWhere(array $conditions): Collection;
    public function findWhereFirst(array $conditions): ?Model;
    public function paginate(array $conditions = [], int $perPage = 15): LengthAwarePaginator;
    public function create(array $data): Model;
    public function update(Model $model, array $data): bool;
    public function delete(Model $model): bool;
    public function with(array $relations): self;
    public function withCount(array $relations): self;
}

interface ContentRepositoryInterface extends EntityRepositoryInterface
{
    public function findPublished(): Collection;
    public function findBySlug(string $slug): ?Model;
    public function findFeatured(int $limit = 5): Collection;
    public function findByCategory(int $categoryId): Collection;
    public function search(string $query): Collection;
}

interface CategoryRepositoryInterface extends EntityRepositoryInterface
{
    public function findActive(): Collection;
    public function findByParent(?int $parentId): Collection;
    public function findWithChildren(): Collection;
    public function reorder(array $items): bool;
    public function findBySlug(string $slug): ?Model;
}

interface MediaRepositoryInterface extends EntityRepositoryInterface
{
    public function findByType(string $type): Collection;
    public function findUnused(): Collection;
    public function attachToContent(int $mediaId, int $contentId): bool;
    public function detachFromContent(int $mediaId, int $contentId): bool;
}

namespace App\Core\Repositories;

use App\Core\Contracts\Repositories\EntityRepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

abstract class AbstractRepository implements EntityRepositoryInterface
{
    protected Model $model;
    protected array $with = [];
    protected array $withCount = [];
    protected int $cacheMinutes = 60;

    public function __construct()
    {
        $this->model = app($this->model());
    }

    abstract protected function model(): string;

    public function find(int $id): ?Model
    {
        return $this->remember(__FUNCTION__, fn() => 
            $this->newQuery()->find($id)
        );
    }

    public function findWhere(array $conditions): Collection
    {
        return $this->remember(__FUNCTION__ . ':' . md5(serialize($conditions)), fn() =>
            $this->newQuery()->where($conditions)->get()
        );
    }

    public function findWhereFirst(array $conditions): ?Model
    {
        return $this->remember(__FUNCTION__ . ':' . md5(serialize($conditions)), fn() =>
            $this->newQuery()->where($conditions)->first()
        );
    }

    public function paginate(array $conditions = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->newQuery();

        if (!empty($conditions)) {
            $query->where($conditions);
        }

        return $query->paginate($perPage);
    }

    public function create(array $data): Model
    {
        return DB::transaction(function () use ($data) {
            $model = $this->newQuery()->create($data);
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

    public function with(array $relations): self
    {
        $this->with = array_merge($this->with, $relations);
        return $this;
    }

    public function withCount(array $relations): self
    {
        $this->withCount = array_merge($this->withCount, $relations);
        return $this;
    }

    protected function newQuery(): Builder
    {
        $query = $this->model->newQuery();

        if (!empty($this->with)) {
            $query->with($this->with);
        }

        if (!empty($this->withCount)) {
            $query->withCount($this->withCount);
        }

        return $query;
    }

    protected function remember(string $key, \Closure $callback)
    {
        return Cache::tags($this->getCacheTags())
            ->remember($this->getCacheKey($key), $this->cacheMinutes * 60, $callback);
    }

    protected function getCacheKey(string $key): string
    {
        return sprintf(
            '%s:%s:%s',
            config('cache.prefix', 'laravel'),
            class_basename($this),
            $key
        );
    }

    protected function getCacheTags(): array
    {
        return [class_basename($this)];
    }

    protected function clearCache(): void
    {
        Cache::tags($this->getCacheTags())->flush();
    }
}

namespace App\Repositories;

use App\Core\Repositories\AbstractRepository;
use App\Core\Contracts\Repositories\ContentRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class ContentRepository extends AbstractRepository implements ContentRepositoryInterface
{
    protected function model(): string
    {
        return \App\Models\Content::class;
    }

    public function findPublished(): Collection
    {
        return $this->remember(__FUNCTION__, fn() =>
            $this->newQuery()
                ->where('status', 'published')
                ->where('published_at', '<=', now())
                ->orderBy('published_at', 'desc')
                ->get()
        );
    }

    public function findBySlug(string $slug): ?Model
    {
        return $this->remember(__FUNCTION__ . ':' . $slug, fn() =>
            $this->newQuery()
                ->where('slug', $slug)
                ->where('status', 'published')
                ->where('published_at', '<=', now())
                ->first()
        );
    }

    public function findFeatured(int $limit = 5): Collection
    {
        return $this->remember(__FUNCTION__ . ':' . $limit, fn() =>
            $this->newQuery()
                ->where('status', 'published')
                ->where('is_featured', true)
                ->where('published_at', '<=', now())
                ->orderBy('published_at', 'desc')
                ->limit($limit)
                ->get()
        );
    }

    public function findByCategory(int $categoryId): Collection
    {
        return $this->remember(__FUNCTION__ . ':' . $categoryId, fn() =>
            $this->newQuery()
                ->where('category_id', $categoryId)
                ->where('status', 'published')
                ->where('published_at', '<=', now())
                ->orderBy('published_at', 'desc')
                ->get()
        );
    }

    public function search(string $query): Collection
    {
        return $this->newQuery()
            ->where('status', 'published')
            ->where('published_at', '<=', now())
            ->where(function($q) use ($query) {
                $q->where('title', 'like', "%{$query}%")
                  ->orWhere('content', 'like', "%{$query}%");
            })
            ->orderBy('published_at', 'desc')
            ->get();
    }
}
