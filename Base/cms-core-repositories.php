<?php

namespace App\Core\Repository;

use App\Core\Contracts\CoreRepositoryInterface;
use App\Core\Events\{ModelCreated, ModelUpdated, ModelDeleted};
use App\Core\Exceptions\CoreException;
use Illuminate\Database\Eloquent\{Model, Builder, Collection};
use Illuminate\Support\{Facades\DB, Facades\Cache};
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

abstract class CoreRepository implements CoreRepositoryInterface
{
    protected Model $model;
    protected array $with = [];
    protected bool $useCache = true;
    protected int $ttl = 3600;

    public function find(int $id): ?Model
    {
        return $this->cache(__METHOD__, [$id], fn() => 
            $this->query()->find($id)
        );
    }

    public function findOrFail(int $id): Model
    {
        $model = $this->find($id);
        if (!$model) throw new CoreException('Model not found');
        return $model;
    }

    public function create(array $data): Model
    {
        return DB::transaction(function() use ($data) {
            $model = $this->query()->create($data);
            $this->clearCache();
            event(new ModelCreated($model));
            return $model;
        });
    }

    public function update(Model $model, array $data): bool
    {
        return DB::transaction(function() use ($model, $data) {
            $updated = $model->update($data);
            if ($updated) {
                $this->clearCache();
                event(new ModelUpdated($model));
            }
            return $updated;
        });
    }

    public function delete(Model $model): bool
    {
        return DB::transaction(function() use ($model) {
            $deleted = $model->delete();
            if ($deleted) {
                $this->clearCache();
                event(new ModelDeleted($model));
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
        return $this->model->newQuery()->with($this->with);
    }

    protected function cache(string $method, array $args, callable $callback)
    {
        if (!$this->useCache) return $callback();

        $key = $this->getCacheKey($method, $args);
        return Cache::tags($this->getCacheTags())
            ->remember($key, $this->ttl, $callback);
    }

    protected function getCacheKey(string $method, array $args): string
    {
        return sprintf(
            '%s.%s.%s',
            class_basename($this->model),
            $method,
            md5(serialize($args))
        );
    }

    protected function getCacheTags(): array
    {
        return ['repository', class_basename($this->model)];
    }

    protected function clearCache(): void
    {
        if ($this->useCache) {
            Cache::tags($this->getCacheTags())->flush();
        }
    }
}

class ContentRepository extends CoreRepository
{
    protected array $with = ['category', 'author'];

    public function __construct(Content $model)
    {
        $this->model = $model;
    }

    public function findBySlug(string $slug): ?Model
    {
        return $this->cache(__METHOD__, [$slug], fn() => 
            $this->query()->where('slug', $slug)->first()
        );
    }

    public function findPublished(): Collection
    {
        return $this->cache(__METHOD__, [], fn() => 
            $this->query()
                ->where('status', 'published')
                ->where('published_at', '<=', now())
                ->orderByDesc('published_at')
                ->get()
        );
    }
}

class CategoryRepository extends CoreRepository
{
    protected array $with = ['parent'];

    public function __construct(Category $model)
    {
        $this->model = $model;
    }

    public function getActive(): Collection
    {
        return $this->cache(__METHOD__, [], fn() => 
            $this->query()
                ->where('active', true)
                ->orderBy('sort_order')
                ->get()
        );
    }
}
