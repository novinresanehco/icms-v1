<?php

namespace App\Core\Repository\Content;

use App\Core\Repository\AbstractRepository;
use App\Core\Repository\Contracts\ContentRepositoryInterface;
use App\Core\Models\Content;
use App\Core\Cache\Contracts\CacheManagerInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class ContentRepository extends AbstractRepository implements ContentRepositoryInterface
{
    protected string $defaultCacheKey = 'content';
    protected int $defaultCacheTtl = 3600; // 1 hour

    public function __construct(CacheManagerInterface $cache)
    {
        parent::__construct($cache);
        $this->cache($this->defaultCacheKey, $this->defaultCacheTtl);
    }

    protected function getModelClass(): string
    {
        return Content::class;
    }

    public function findPublished($id): ?Content
    {
        return $this->model
            ->where('id', $id)
            ->where('status', 'published')
            ->first();
    }

    public function findBySlug(string $slug): ?Content
    {
        if ($this->enableCache) {
            return $this->cache->remember(
                $this->getCacheKey("slug.{$slug}"),
                fn() => $this->model->where('slug', $slug)->first(),
                $this->cacheTtl
            );
        }

        return $this->model->where('slug', $slug)->first();
    }

    public function getPublished(array $options = []): Collection
    {
        $query = $this->model
            ->where('status', 'published')
            ->orderBy($options['sort'] ?? 'created_at', $options['direction'] ?? 'desc');

        if ($this->enableCache) {
            return $this->cache->remember(
                $this->getCacheKey('published.' . md5(serialize($options))),
                fn() => $query->get(),
                $this->cacheTtl
            );
        }

        return $query->get();
    }

    public function paginatePublished(int $perPage = 15, array $options = []): LengthAwarePaginator
    {
        $query = $this->model
            ->where('status', 'published')
            ->orderBy($options['sort'] ?? 'created_at', $options['direction'] ?? 'desc');

        // Pagination is not cached as it depends on the current page
        return $query->paginate($perPage);
    }

    public function findByCategory(int $categoryId, array $options = []): Collection
    {
        $query = $this->model
            ->where('category_id', $categoryId)
            ->orderBy($options['sort'] ?? 'created_at', $options['direction'] ?? 'desc');

        if ($this->enableCache) {
            return $this->cache->remember(
                $this->getCacheKey("category.{$categoryId}." . md5(serialize($options))),
                fn() => $query->get(),
                $this->cacheTtl
            );
        }

        return $query->get();
    }

    public function findByTags(array $tagIds, array $options = []): Collection
    {
        $query = $this->model
            ->whereHas('tags', function ($query) use ($tagIds) {
                $query->whereIn('tags.id', $tagIds);
            })
            ->orderBy($options['sort'] ?? 'created_at', $options['direction'] ?? 'desc');

        if ($this->enableCache) {
            return $this->cache->remember(
                $this->getCacheKey("tags." . md5(serialize($tagIds)) . "." . md5(serialize($options))),
                fn() => $query->get(),
                $this->cacheTtl
            );
        }

        return $query->get();
    }

    public function search(string $term, array $options = []): Collection
    {
        $query = $this->model
            ->where(function ($query) use ($term) {
                $query->where('title', 'LIKE', "%{$term}%")
                    ->orWhere('content', 'LIKE', "%{$term}%");
            })
            ->orderBy($options['sort'] ?? 'created_at', $options['direction'] ?? 'desc');

        // Search results are not cached as they may