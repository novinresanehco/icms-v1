```php
<?php

namespace App\Core\Repository;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use App\Core\Exceptions\RepositoryException;
use Illuminate\Support\Facades\DB;
use Exception;

abstract class BaseRepository
{
    protected Model $model;
    protected array $with = [];
    protected int $cacheTTL = 3600;
    protected bool $useCache = true;
    protected string $cachePrefix = 'cms';

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    protected function getCacheKey(string $key): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->cachePrefix,
            strtolower(class_basename($this->model)),
            $key
        );
    }

    public function find(int $id): ?Model
    {
        $cacheKey = $this->getCacheKey("find.{$id}");

        if ($this->useCache) {
            return Cache::remember($cacheKey, $this->cacheTTL, function() use ($id) {
                return $this->model->with($this->with)->find($id);
            });
        }

        return $this->model->with($this->with)->find($id);
    }

    public function findOrFail(int $id): Model
    {
        $result = $this->find($id);

        if (!$result) {
            throw new RepositoryException("Resource not found with ID: {$id}");
        }

        return $result;
    }

    public function all(): Collection
    {
        $cacheKey = $this->getCacheKey('all');

        if ($this->useCache) {
            return Cache::remember($cacheKey, $this->cacheTTL, function() {
                return $this->model->with($this->with)->get();
            });
        }

        return $this->model->with($this->with)->get();
    }

    public function create(array $data): Model
    {
        DB::beginTransaction();
        
        try {
            $model = $this->model->create($data);
            DB::commit();
            $this->clearCache();
            
            return $model;
        } catch (Exception $e) {
            DB::rollBack();
            throw new RepositoryException("Failed to create resource: {$e->getMessage()}");
        }
    }

    public function update(int $id, array $data): Model
    {
        DB::beginTransaction();
        
        try {
            $model = $this->findOrFail($id);
            $model->update($data);
            DB::commit();
            $this->clearCache();
            
            return $model;
        } catch (Exception $e) {
            DB::rollBack();
            throw new RepositoryException("Failed to update resource: {$e->getMessage()}");
        }
    }

    public function delete(int $id): bool
    {
        DB::beginTransaction();
        
        try {
            $model = $this->findOrFail($id);
            $deleted = $model->delete();
            DB::commit();
            $this->clearCache();
            
            return $deleted;
        } catch (Exception $e) {
            DB::rollBack();
            throw new RepositoryException("Failed to delete resource: {$e->getMessage()}");
        }
    }

    public function paginate(int $perPage = 15)
    {
        return $this->model->with($this->with)->paginate($perPage);
    }

    public function with(array $relations): self
    {
        $this->with = array_merge($this->with, $relations);
        return $this;
    }

    protected function clearCache(): void
    {
        $tag = strtolower(class_basename($this->model));
        Cache::tags([$tag])->flush();
    }

    protected function query(): Builder
    {
        return $this->model->newQuery();
    }
}

class ContentRepository extends BaseRepository
{
    protected array $with = ['tags', 'author', 'media'];

    public function findBySlug(string $slug): ?Model
    {
        $cacheKey = $this->getCacheKey("slug.{$slug}");

        if ($this->useCache) {
            return Cache::remember($cacheKey, $this->cacheTTL, function() use ($slug) {
                return $this->query()
                    ->with($this->with)
                    ->where('slug', $slug)
                    ->first();
            });
        }

        return $this->query()
            ->with($this->with)
            ->where('slug', $slug)
            ->first();
    }

    public function findPublished(): Collection
    {
        $cacheKey = $this->getCacheKey('published');

        if ($this->useCache) {
            return Cache::remember($cacheKey, $this->cacheTTL, function() {
                return $this->query()
                    ->with($this->with)
                    ->where('status', 'published')
                    ->orderBy('published_at', 'desc')
                    ->get();
            });
        }

        return $this->query()
            ->with($this->with)
            ->where('status', 'published')
            ->orderBy('published_at', 'desc')
            ->get();
    }

    public function updateStatus(int $id, string $status): Model
    {
        return $this->update($id, ['status' => $status]);
    }
}

class TagRepository extends BaseRepository
{
    public function findByName(string $name): ?Model
    {
        $cacheKey = $this->getCacheKey("name.{$name}");

        if ($this->useCache) {
            return Cache::remember($cacheKey, $this->cacheTTL, function() use ($name) {
                return $this->query()->where('name', $name)->first();
            });
        }

        return $this->query()->where('name', $name)->first();
    }

    public function getPopularTags(int $limit = 10): Collection
    {
        $cacheKey = $this->getCacheKey("popular.{$limit}");

        if ($this->useCache) {
            return Cache::remember($cacheKey, $this->cacheTTL, function() use ($limit) {
                return $this->query()
                    ->withCount('contents')
                    ->orderBy('contents_count', 'desc')
                    ->limit($limit)
                    ->get();
            });
        }

        return $this->query()
            ->withCount('contents')
            ->orderBy('contents_count', 'desc')
            ->limit($limit)
            ->get();
    }

    public function attachToContent(int $contentId, array $tagIds): void
    {
        DB::beginTransaction();
        
        try {
            $content = app(ContentRepository::class)->findOrFail($contentId);
            $content->tags()->sync($tagIds);
            DB::commit();
            $this->clearCache();
        } catch (Exception $e) {
            DB::rollBack();
            throw new RepositoryException("Failed to attach tags to content: {$e->getMessage()}");
        }
    }
}

class MediaRepository extends BaseRepository
{
    public function findByType(string $type): Collection
    {
        $cacheKey = $this->getCacheKey("type.{$type}");

        if ($this->useCache) {
            return Cache::remember($cacheKey, $this->cacheTTL, function() use ($type) {
                return $this->query()->where('type', $type)->get();
            });
        }

        return $this->query()->where('type', $type)->get();
    }

    public function attachToContent(int $contentId, array $mediaIds): void
    {
        DB::beginTransaction();
        
        try {
            $content = app(ContentRepository::class)->findOrFail($contentId);
            $content->media()->sync($mediaIds);
            DB::commit();
            $this->clearCache();
        } catch (Exception $e) {
            DB::rollBack();
            throw new RepositoryException("Failed to attach media to content: {$e->getMessage()}");
        }
    }
}
```
