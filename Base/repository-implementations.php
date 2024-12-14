<?php

namespace App\Core\Repositories;

interface RepositoryInterface
{
    public function all(array $columns = ['*']): Collection;
    public function find(int $id, array $columns = ['*']): ?Model;
    public function findBy(array $criteria, array $columns = ['*']): Collection;
    public function findOneBy(array $criteria, array $columns = ['*']): ?Model;
    public function create(array $attributes): Model;
    public function update(int $id, array $attributes): bool;
    public function delete(int $id): bool;
}

abstract class BaseRepository implements RepositoryInterface
{
    use HasCache;

    protected Model $model;

    public function __construct(protected Container $app)
    {
        $this->model = $app->make($this->model());
    }

    abstract protected function model(): string;

    public function all(array $columns = ['*']): Collection
    {
        return $this->remember(fn() => $this->newQuery()->get($columns));
    }

    public function find(int $id, array $columns = ['*']): ?Model
    {
        return $this->remember(fn() => $this->newQuery()->find($id, $columns));
    }

    public function findBy(array $criteria, array $columns = ['*']): Collection
    {
        return $this->remember(function() use ($criteria, $columns) {
            $query = $this->newQuery();
            
            foreach ($criteria as $field => $value) {
                $query->where($field, $value);
            }
            
            return $query->get($columns);
        });
    }

    public function findOneBy(array $criteria, array $columns = ['*']): ?Model
    {
        return $this->remember(function() use ($criteria, $columns) {
            $query = $this->newQuery();
            
            foreach ($criteria as $field => $value) {
                $query->where($field, $value);
            }
            
            return $query->first($columns);
        });
    }

    public function create(array $attributes): Model
    {
        $model = $this->newQuery()->create($attributes);
        $this->clearCache();
        return $model;
    }

    public function update(int $id, array $attributes): bool
    {
        $updated = $this->newQuery()->where('id', $id)->update($attributes);
        if ($updated) {
            $this->clearCache();
        }
        return (bool) $updated;
    }

    public function delete(int $id): bool
    {
        $deleted = $this->newQuery()->where('id', $id)->delete();
        if ($deleted) {
            $this->clearCache();
        }
        return (bool) $deleted;
    }

    protected function newQuery(): Builder
    {
        return $this->model->newQuery();
    }
}

trait HasCache
{
    protected function getCacheKey(string $method, array $args = []): string
    {
        return sprintf(
            '%s:%s:%s:%s',
            config('cache.prefix', 'laravel'),
            class_basename($this),
            $method,
            md5(serialize($args))
        );
    }

    protected function remember(Closure $callback)
    {
        $key = $this->getCacheKey(debug_backtrace()[1]['function'], func_get_args());
        
        return Cache::tags($this->getCacheTags())->remember(
            $key,
            config('cache.ttl', 3600),
            $callback
        );
    }

    protected function clearCache(): bool
    {
        return Cache::tags($this->getCacheTags())->flush();
    }

    protected function getCacheTags(): array
    {
        return [class_basename($this)];
    }
}

class ContentRepository extends BaseRepository
{
    protected function model(): string
    {
        return Content::class;
    }

    public function findPublished(array $columns = ['*']): Collection
    {
        return $this->remember(function() use ($columns) {
            return $this->newQuery()
                ->where('status', 'published')
                ->where('published_at', '<=', now())
                ->orderBy('published_at', 'desc')
                ->get($columns);
        });
    }

    public function findBySlug(string $slug, array $columns = ['*']): ?Model
    {
        return $this->remember(function() use ($slug, $columns) {
            return $this->newQuery()
                ->where('slug', $slug)
                ->where('status', 'published')
                ->where('published_at', '<=', now())
                ->first($columns);
        });
    }

    public function searchContent(string $query, array $columns = ['*']): Collection
    {
        return $this->remember(function() use ($query, $columns) {
            return $this->newQuery()
                ->where('status', 'published')
                ->where('published_at', '<=', now())
                ->where(function($q) use ($query) {
                    $q->where('title', 'like', "%{$query}%")
                      ->orWhere('content', 'like', "%{$query}%");
                })
                ->orderBy('published_at', 'desc')
                ->get($columns);
        });
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
        return $this->remember(fn() => 
            $this->newQuery()
                ->where('slug', $slug)
                ->where('active', true)
                ->first()
        );
    }

    public function findActive(): Collection
    {
        return $this->remember(fn() =>
            $this->newQuery()
                ->where('active', true)
                ->orderBy('sort_order')
                ->get()
        );
    }

    public function reorder(array $items): bool
    {
        DB::beginTransaction();
        
        try {
            foreach ($items as $order => $id) {
                $this->update($id, ['sort_order' => $order]);
            }
            
            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollBack();
            return false;
        }
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
        return $this->remember(fn() =>
            $this->newQuery()
                ->orderBy('usage_count', 'desc')
                ->limit($limit)
                ->get()
        );
    }

    public function incrementUsage(int $id): bool
    {
        $updated = $this->newQuery()
            ->where('id', $id)
            ->increment('usage_count');
            
        if ($updated) {
            $this->clearCache();
        }
        
        return (bool) $updated;
    }
}
