<?php

namespace App\Core\Repositories;

use App\Core\Contracts\RepositoryInterface;
use App\Core\Traits\HasCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Core\Exceptions\RepositoryException;

interface CriteriaInterface
{
    public function apply(Builder $query): Builder;
}

abstract class BaseRepository implements RepositoryInterface
{
    use HasCache;

    protected Model $model;
    protected array $criteria = [];
    protected array $with = [];
    protected bool $skipCriteria = false;

    public function __construct()
    {
        $this->model = app($this->model());
        $this->resetScope();
    }

    abstract protected function model(): string;

    public function all(array $columns = ['*'], array $with = []): Collection
    {
        $this->applyWith($with);
        return $this->cacheQuery(fn() => 
            $this->applyCriteria()->select($columns)->get()
        );
    }

    public function find(int $id, array $columns = ['*'], array $with = []): ?Model
    {
        $this->applyWith($with);
        return $this->cacheQuery(fn() => 
            $this->applyCriteria()->find($id, $columns)
        );
    }

    public function findWhere(array $where, array $columns = ['*'], array $with = []): Collection
    {
        $this->applyWith($with);
        return $this->cacheQuery(fn() => 
            $this->applyCriteria()->where($where)->select($columns)->get()
        );
    }

    public function paginate(int $perPage = 15, array $columns = ['*'], array $with = []): LengthAwarePaginator
    {
        $this->applyWith($with);
        return $this->applyCriteria()->select($columns)->paginate($perPage);
    }

    public function create(array $data): Model
    {
        $model = $this->model->create($data);
        $this->clearCache();
        return $model;
    }

    public function update(Model $model, array $data): bool
    {
        $updated = $model->update($data);
        if ($updated) {
            $this->clearCache();
        }
        return $updated;
    }

    public function delete(Model $model): bool
    {
        $deleted = $model->delete();
        if ($deleted) {
            $this->clearCache();
        }
        return $deleted;
    }

    public function pushCriteria(CriteriaInterface $criteria): self
    {
        $this->criteria[] = $criteria;
        return $this;
    }

    public function skipCriteria(bool $skip = true): self
    {
        $this->skipCriteria = $skip;
        return $this;
    }

    public function resetScope(): self
    {
        $this->skipCriteria = false;
        $this->criteria = [];
        $this->with = [];
        return $this;
    }

    protected function applyCriteria(): Builder
    {
        $query = $this->model->newQuery();

        if ($this->skipCriteria) {
            return $query;
        }

        foreach ($this->criteria as $criteria) {
            $query = $criteria->apply($query);
        }

        if (!empty($this->with)) {
            $query->with($this->with);
        }

        return $query;
    }

    protected function applyWith(array $with): void
    {
        $this->with = array_merge($this->with, $with);
    }
}

class PublishedContentCriteria implements CriteriaInterface
{
    public function apply(Builder $query): Builder
    {
        return $query->where('status', 'published')
                    ->where('published_at', '<=', now())
                    ->orderBy('published_at', 'desc');
    }
}

class FeaturedContentCriteria implements CriteriaInterface
{
    private int $limit;

    public function __construct(int $limit = 5)
    {
        $this->limit = $limit;
    }

    public function apply(Builder $query): Builder
    {
        return $query->where('is_featured', true)
                    ->limit($this->limit);
    }
}

class CategoryRepository extends BaseRepository
{
    protected function model(): string
    {
        return Category::class;
    }

    public function findBySlug(string $slug): ?Model
    {
        return $this->cacheQuery(fn() => 
            $this->applyCriteria()
                ->where('slug', $slug)
                ->where('active', true)
                ->first()
        );
    }

    public function findActive(): Collection
    {
        return $this->cacheQuery(fn() => 
            $this->applyCriteria()
                ->where('active', true)
                ->orderBy('sort_order')
                ->get()
        );
    }

    public function reorder(array $items): bool
    {
        $updated = collect($items)->map(function($item, $index) {
            return $this->model->where('id', $item['id'])
                             ->update(['sort_order' => $index + 1]);
        })->every(fn($result) => $result === true);

        if ($updated) {
            $this->clearCache();
        }

        return $updated;
    }
}

class ContentRepository extends BaseRepository
{
    protected function model(): string
    {
        return Content::class;
    }

    public function findPublished(): Collection
    {
        return $this->pushCriteria(new PublishedContentCriteria())
                    ->all(['*'], ['category', 'author', 'tags']);
    }

    public function findFeatured(int $limit = 5): Collection
    {
        return $this->pushCriteria(new PublishedContentCriteria())
                    ->pushCriteria(new FeaturedContentCriteria($limit))
                    ->all(['*'], ['category', 'author']);
    }

    public function findBySlug(string $slug): ?Model
    {
        return $this->cacheQuery(fn() => 
            $this->pushCriteria(new PublishedContentCriteria())
                ->applyCriteria()
                ->where('slug', $slug)
                ->with(['category', 'author', 'tags'])
                ->first()
        );
    }

    public function search(string $query): Collection
    {
        return $this->pushCriteria(new PublishedContentCriteria())
                    ->findWhere([
                        ['title', 'like', "%{$query}%"],
                        ['content', 'like', "%{$query}%"]
                    ], ['*'], ['category', 'author']);
    }
}

class TagRepository extends BaseRepository
{
    protected function model(): string
    {
        return Tag::class;
    }

    public function findPopular(int $limit = 10): Collection
    {
        return $this->cacheQuery(fn() => 
            $this->applyCriteria()
                ->orderBy('usage_count', 'desc')
                ->limit($limit)
                ->get()
        );
    }

    public function incrementUsage(int $id): bool
    {
        $updated = $this->model->where('id', $id)->increment('usage_count');
        if ($updated) {
            $this->clearCache();
        }
        return (bool)$updated;
    }
}
