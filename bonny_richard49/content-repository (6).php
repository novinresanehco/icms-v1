<?php

namespace App\Core\Content\Repository;

use App\Core\Content\Models\Content;
use App\Core\Shared\Repository\BaseRepository;
use App\Core\Shared\Cache\CacheManagerInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ContentRepository extends BaseRepository implements ContentRepositoryInterface
{
    /**
     * Cache configuration
     */
    protected const CACHE_KEY = 'content';
    protected const CACHE_TTL = 3600; // 1 hour

    /**
     * @param CacheManagerInterface $cache
     */
    public function __construct(CacheManagerInterface $cache)
    {
        parent::__construct($cache);
        $this->cache($this->getCacheKey(), static::CACHE_TTL);
    }

    /**
     * @inheritDoc
     */
    protected function getModelClass(): string
    {
        return Content::class;
    }

    /**
     * @inheritDoc
     */
    public function findBySlug(string $slug): ?Content
    {
        $cacheKey = $this->getCacheKey("slug.{$slug}");

        return $this->cache->remember($cacheKey, fn() => 
            $this->model->where('slug', $slug)
                       ->with(['category', 'tags', 'author'])
                       ->first()
        );
    }

    /**
     * @inheritDoc
     */
    public function findPublished(int $id): ?Content
    {
        $cacheKey = $this->getCacheKey("published.{$id}");

        return $this->cache->remember($cacheKey, fn() =>
            $this->model->where('id', $id)
                       ->where('status', 'published')
                       ->with(['category', 'tags', 'author'])
                       ->first()
        );
    }

    /**
     * @inheritDoc
     */
    public function paginatePublished(int $perPage = 15, array $options = []): LengthAwarePaginator
    {
        $query = $this->model->where('status', 'published')
                            ->with(['category', 'tags', 'author']);

        if (isset($options['category_id'])) {
            $query->where('category_id', $options['category_id']);
        }

        if (isset($options['tag_id'])) {
            $query->whereHas('tags', function($q) use ($options) {
                $q->where('tags.id', $options['tag_id']);
            });
        }

        return $query->orderBy($options['sort'] ?? 'created_at', $options['direction'] ?? 'desc')
                    ->paginate($perPage);
    }

    /**
     * @inheritDoc
     */
    public function findByCategory(int $categoryId, array $options = []): Collection
    {
        $cacheKey = $this->getCacheKey("category.{$categoryId}" . md5(serialize($options)));

        return $this->cache->remember($cacheKey, fn() =>
            $this->model->where('category_id', $categoryId)
                       ->where('status', 'published')
                       ->with(['category', 'tags', 'author'])
                       ->orderBy($options['sort'] ?? 'created_at', $options['direction'] ?? 'desc')
                       ->get()
        );
    }

    /**
     * @inheritDoc
     */
    public function findByTags(array $tagIds, array $options = []): Collection
    {
        $cacheKey = $this->getCacheKey("tags." . md5(serialize($tagIds)) . md5(serialize($options)));

        return $this->cache->remember($cacheKey, fn() =>
            $this->model->whereHas('tags', function($query) use ($tagIds) {
                $query->whereIn('tags.id', $tagIds);
            })
            ->where('status', 'published')
            ->with(['category', 'tags', 'author'])
            ->orderBy($options['sort'] ?? 'created_at', $options['direction'] ?? 'desc')
            ->get()
        );
    }

    /**
     * @inheritDoc
     */
    public function search(string $query, array $options = []): Collection
    {
        // Search results are not cached as they may change frequently
        return $this->model->where(function($q) use ($query) {
            $q->where('title', 'LIKE', "%{$query}%")
              ->orWhere('content', 'LIKE', "%{$query}%");
        })
        ->where('status', 'published')
        ->with(['category', 'tags', 'author'])
        ->orderBy($options['sort'] ?? 'relevance', $options['direction'] ?? 'desc')
        ->get();
    }

    /**
     * @inheritDoc
     */
    public function getFeatured(int $limit = 5): Collection
    {
        $cacheKey = $this->getCacheKey("featured.{$limit}");

        return $this->cache->remember($cacheKey, fn() =>
            $this->model->where('status', 'published')
                       ->where('is_featured', true)
                       ->with(['category', 'tags', 'author'])
                       ->orderBy('created_at', 'desc')
                       ->limit($limit)
                       ->get()
        );
    }

    /**
     * @inheritDoc
     */
    public function getLatest(int $limit = 10): Collection
    {
        $cacheKey = $this->getCacheKey("latest.{$limit}");

        return $this->cache->remember($cacheKey, fn() =>
            $this->model->where('status', 'published')
                       ->with(['category', 'tags', 'author'])
                       ->orderBy('created_at', 'desc')
                       ->limit($limit)
                       ->get()
        );
    }

    /**
     * @inheritDoc
     */
    public function getPopular(int $limit = 10): Collection
    {
        $cacheKey = $this->getCacheKey("popular.{$limit}");

        return $this->cache->remember($cacheKey, fn() =>
            $this->model->where('status', 'published')
                       ->with(['category', 'tags', 'author'])
                       ->orderBy('views', 'desc')
                       ->limit($limit)
                       ->get()
        );
    }

    /**
     * @inheritDoc
     */
    public function getRelated(Content $content, int $limit = 5): Collection
    {
        $cacheKey = $this->getCacheKey("related.{$content->id}.{$limit}");

        return $this->cache->remember($cacheKey, fn() =>
            $this->model->where('status', 'published')
                       ->where('id', '!=', $content->id)
                       ->where(function($query) use ($content) {
                           $query->where('category_id', $content->category_id)
                                ->orWhereHas('tags', function($q) use ($content) {
                                    $q->whereIn('tags.id', $content->tags->pluck('id'));
                                });
                       })
                       ->with(['category', 'tags', 'author'])
                       ->orderBy('created_at', 'desc')
                       ->limit($limit)
                       ->get()
        );
    }

    /**
     * Get cache key with prefix.
     *
     * @param string $key
     * @return string
     */
    protected function getCacheKey(string $key = ''): string
    {
        return static::CACHE_KEY . ($key ? ".{$key}" : '');
    }
}
