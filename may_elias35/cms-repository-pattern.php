<?php

namespace App\Core\Repository;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use App\Core\Cache\CacheManager;
use App\Core\Exceptions\RepositoryException;

abstract class BaseRepository
{
    protected Model $model;
    protected CacheManager $cache;
    protected array $criteria = [];
    protected array $with = [];

    public function __construct(Model $model, CacheManager $cache)
    {
        $this->model = $model;
        $this->cache = $cache;
    }

    abstract public function model(): string;

    public function find(int $id): ?Model
    {
        return $this->cache->remember(
            $this->getCacheKey("find.{$id}"),
            3600,
            fn() => $this->prepareCriteria()->with($this->with)->find($id)
        );
    }

    public function findOrFail(int $id): Model
    {
        $result = $this->find($id);

        if (!$result) {
            throw new RepositoryException("Resource with ID {$id} not found");
        }

        return $result;
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
            $deleted = $model->delete();
            $this->clearCache();
            return $deleted;
        } catch (\Exception $e) {
            throw new RepositoryException("Failed to delete resource: {$e->getMessage()}");
        }
    }

    public function all(): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey('all'),
            3600,
            fn() => $this->prepareCriteria()->with($this->with)->get()
        );
    }

    public function paginate(int $perPage = 15): \Illuminate\Pagination\LengthAwarePaginator
    {
        return $this->prepareCriteria()->with($this->with)->paginate($perPage);
    }

    public function with(array $relations): self
    {
        $this->with = array_merge($this->with, $relations);
        return $this;
    }

    protected function prepareCriteria(): Model
    {
        $query = $this->model->newQuery();

        foreach ($this->criteria as $criterion) {
            $query = $criterion->apply($query);
        }

        return $query;
    }

    protected function getCacheKey(string $suffix): string
    {
        return sprintf(
            '%s.%s.%s',
            strtolower(class_basename($this->model())),
            $suffix,
            md5(serialize($this->criteria))
        );
    }

    protected function clearCache(): void
    {
        $this->cache->tags([
            strtolower(class_basename($this->model()))
        ])->flush();
    }
}

// Example Content Repository Implementation
class ContentRepository extends BaseRepository
{
    public function model(): string
    {
        return Content::class;
    }

    public function findBySlug(string $slug): ?Content
    {
        return $this->cache->remember(
            $this->getCacheKey("slug.{$slug}"),
            3600,
            fn() => $this->model->where('slug', $slug)->first()
        );
    }

    public function findPublished(): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey('published'),
            3600,
            fn() => $this->model->where('status', 'published')
                               ->orderBy('published_at', 'desc')
                               ->get()
        );
    }

    public function updateStatus(int $id, string $status): Content
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

// Example Tag Repository Implementation
class TagRepository extends BaseRepository
{
    public function model(): string
    {
        return Tag::class;
    }

    public function findByName(string $name): ?Tag
    {
        return $this->cache->remember(
            $this->getCacheKey("name.{$name}"),
            3600,
            fn() => $this->model->where('name', $name)->first()
        );
    }

    public function getPopularTags(int $limit = 10): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey("popular.{$limit}"),
            3600,
            fn() => $this->model->withCount('contents')
                               ->orderBy('contents_count', 'desc')
                               ->limit($limit)
                               ->get()
        );
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

// Example Media Repository Implementation
class MediaRepository extends BaseRepository
{
    public function model(): string
    {
        return Media::class;
    }

    public function findByType(string $type): Collection
    {
        return $this->cache->remember(
            $this->getCacheKey("type.{$type}"),
            3600,
            fn() => $this->model->where('type', $type)->get()
        );
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

    public function updateMetadata(int $id, array $metadata): Media
    {
        try {
            $media = $this->findOrFail($id);
            $media->update(['metadata' => array_merge($media->metadata ?? [], $metadata)]);
            $this->clearCache();
            return $media;
        } catch (\Exception $e) {
            throw new RepositoryException("Failed to update media metadata: {$e->getMessage()}");
        }
    }
}
