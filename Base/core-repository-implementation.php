<?php

namespace App\Core\Repositories;

use App\Core\Contracts\RepositoryInterface;
use App\Core\Support\Cache\CacheManager;
use App\Core\Events\{DataCreated, DataUpdated, DataDeleted};
use App\Core\Exceptions\RepositoryException;
use Illuminate\Database\Eloquent\{Model, Builder, Collection};
use Illuminate\Support\Facades\{DB, Event};
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

abstract class Repository implements RepositoryInterface
{
    protected Model $model;
    protected array $with = [];
    protected array $withCount = [];
    protected bool $enableCache = true;
    protected int $cacheDuration = 3600;

    public function find(int $id): ?Model
    {
        return $this->remember(fn() => 
            $this->query()->find($id)
        );
    }

    public function findOrFail(int $id): Model
    {
        if (!$model = $this->find($id)) {
            throw new RepositoryException('Model not found');
        }
        return $model;
    }

    public function create(array $attributes): Model
    {
        return DB::transaction(function() use ($attributes) {
            $model = $this->query()->create($attributes);
            $this->clearCache();
            Event::dispatch(new DataCreated($model));
            return $model;
        });
    }

    public function update(Model $model, array $attributes): bool
    {
        return DB::transaction(function() use ($model, $attributes) {
            if ($updated = $model->update($attributes)) {
                $this->clearCache();
                Event::dispatch(new DataUpdated($model));
            }
            return $updated;
        });
    }

    public function delete(Model $model): bool
    {
        return DB::transaction(function() use ($model) {
            if ($deleted = $model->delete()) {
                $this->clearCache();
                Event::dispatch(new DataDeleted($model));
            }
            return $deleted;
        });
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->query()->paginate($perPage);
    }

    protected function query(): Builder
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

    protected function remember(callable $callback)
    {
        if (!$this->enableCache) {
            return $callback();
        }

        $key = $this->getCacheKey(debug_backtrace()[1]['function']);
        return CacheManager::remember($key, $this->cacheDuration, $callback);
    }

    protected function getCacheKey(string $method): string
    {
        return sprintf(
            'repository.%s.%s',
            class_basename($this->model),
            $method
        );
    }

    protected function clearCache(): void
    {
        if ($this->enableCache) {
            CacheManager::flush($this->getCacheKey('*'));
        }
    }
}

class ContentRepository extends Repository
{
    protected array $with = ['category', 'author', 'tags'];
    protected array $withCount = ['comments'];

    public function findBySlug(string $slug): ?Model
    {
        return $this->remember(fn() => 
            $this->query()
                ->where('slug', $slug)
                ->first()
        );
    }

    public function findPublished(): Collection
    {
        return $this->remember(fn() => 
            $this->query()
                ->where('status', 'published')
                ->where('published_at', '<=', now())
                ->orderByDesc('published_at')
                ->get()
        );
    }

    public function updateStatus(Model $content, string $status): bool
    {
        if (!in_array($status, ['draft', 'published', 'archived'])) {
            throw new RepositoryException('Invalid status');
        }

        $attributes = ['status' => $status];
        
        if ($status === 'published') {
            $attributes['published_at'] = now();
        }

        return $this->update($content, $attributes);
    }
}

class CategoryRepository extends Repository
{
    protected array $with = ['parent'];

    public function getActive(): Collection
    {
        return $this->remember(fn() => 
            $this->query()
                ->where('active', true)
                ->orderBy('sort_order')
                ->get()
        );
    }

    public function reorder(array $items): bool
    {
        return DB::transaction(function() use ($items) {
            foreach ($items as $index => $item) {
                $this->model
                    ->where('id', $item['id'])
                    ->update(['sort_order' => $index + 1]);
            }
            $this->clearCache();
            return true;
        });
    }
}
