```php
<?php

namespace App\Core\Repository;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use App\Core\Cache\CacheManager;
use App\Core\Exceptions\RepositoryException;

abstract class BaseRepository
{
    protected Model $model;
    protected CacheManager $cache;
    protected array $with = [];
    protected int $cacheDuration = 3600;

    public function __construct(Model $model, CacheManager $cache)
    {
        $this->model = $model;
        $this->cache = $cache;
    }

    protected function getCacheKey(string $key): string
    {
        return sprintf(
            'cms:%s:%s',
            strtolower(class_basename($this->model)),
            $key
        );
    }

    public function find(int $id): ?Model
    {
        $cacheKey = $this->getCacheKey("find.$id");

        return $this->cache->remember($cacheKey, $this->cacheDuration, function () use ($id) {
            return $this->model->with($this->with)->find($id);
        });
    }

    public function findOrFail(int $id): Model
    {
        $model = $this->find($id);

        if (!$model) {
            throw new RepositoryException("Resource not found with ID: $id");
        }

        return $model;
    }

    public function all(): Collection
    {
        $cacheKey = $this->getCacheKey('all');

        return $this->cache->remember($cacheKey, $this->cacheDuration, function () {
            return $this->model->with($this->with)->get();
        });
    }

    public function create(array $data): Model
    {
        try {
            $model = $this->model->create($data);
            $this->clearCache();
            return $model;
        } catch (\Exception $e) {
            throw new RepositoryException("Failed to create resource: {$e->getMessage()}");
        }
    }

    public function update(int $id, array $data): Model
    {
        try {
            $model = $this->findOrFail($id);
            $model->update($data);
            $this->clearCache();
            return $model;
        } catch (\Exception $e) {
            throw new RepositoryException("Failed to update resource: {$e->getMessage()}");
        }
    }

    public function delete(int $id): bool
    {
        try {
            $model = $this->findOrFail($id);
            $result = $model->delete();
            $this->clearCache();
            return $result;
        } catch (\Exception $e) {
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
        $this->cache->tags([$tag])->flush();
    }
}

class ContentRepository extends BaseRepository
{
    protected array $with = ['tags', 'author', 'media'];
    
    public function findBySlug(string $slug): ?Model
    {
        $cacheKey = $this->getCacheKey("slug.$slug");

        return $this->cache->remember($cacheKey, $this->cacheDuration, function () use ($slug) {
            return $this->model->with($this->with)
                             ->where('slug', $slug)
                             ->first();
        });
    }

    public function findPublished(): Collection
    {
        $cacheKey = $this->getCacheKey('published');

        return $this->cache->remember($cacheKey, $this->cacheDuration, function () {
            return $this->model->with($this->with)
                             ->where('status', 'published')
                             ->orderBy('published_at', 'desc')
                             ->get();
        });
    }

    public function updateStatus(int $id, string $status): Model
    {
        try {
            $content = $this->findOrFail($id);
            $content->update(['status' => $status]);
            $this->clearCache();
            return $content;
        } catch (\Exception $e) {
            throw new RepositoryException("Failed to update content status: {$e->getMessage()}");
        }
    }
}

class TagRepository extends BaseRepository
{
    public function findByName(string $name): ?Model
    {
        $cacheKey = $this->getCacheKey("name.$name");

        return $this->cache->remember($cacheKey, $this->cacheDuration, function () use ($name) {
            return $this->model->where('name', $name)->first();
        });
    }

    public function getPopularTags(int $limit = 10): Collection
    {
        $cacheKey = $this->getCacheKey("popular.$limit");

        return $this->cache->remember($cacheKey, $this->cacheDuration, function () use ($limit) {
            return $this->model->withCount('contents')
                             ->orderBy('contents_count', 'desc')
                             ->limit($limit)
                             ->get();
        });
    }

    public function attachToContent(int $contentId, array $tagIds): void
    {
        try {
            $content = app(ContentRepository::class)->findOrFail($contentId);
            $content->tags()->sync($tagIds);
            $this->clearCache();
        } catch (\Exception $e) {
            throw new RepositoryException("Failed to attach tags to content: {$e->getMessage()}");
        }
    }
}

class MediaRepository extends BaseRepository
{
    public function findByType(string $type): Collection
    {
        $cacheKey = $this->getCacheKey("type.$type");

        return $this->cache->remember($cacheKey, $this->cacheDuration, function () use ($type) {
            return $this->model->where('type', $type)->get();
        });
    }

    public function attachToContent(int $contentId, array $mediaIds): void
    {
        try {
            $content = app(ContentRepository::class)->findOrFail($contentId);
            $content->media()->sync($mediaIds);
            $this->clearCache();
        } catch (\Exception $e) {
            throw new RepositoryException("Failed to attach media to content: {$e->getMessage()}");
        }
    }
}
```
