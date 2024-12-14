<?php

namespace App\Core\Tag\Services;

use App\Core\Tag\Models\Tag;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

class TagCacheService
{
    /**
     * @var CacheRepository
     */
    protected CacheRepository $cache;

    /**
     * Cache TTL in seconds.
     */
    protected const CACHE_TTL = 3600; // 1 hour

    /**
     * @param CacheRepository $cache
     */
    public function __construct(CacheRepository $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Get a tag by ID from cache or database.
     */
    public function getTag(int $id): ?Tag
    {
        $key = $this->getTagKey($id);

        return $this->cache->tags(['tags'])->remember(
            $key,
            static::CACHE_TTL,
            fn() => Tag::find($id)
        );
    }

    /**
     * Get content tags from cache or database.
     */
    public function getContentTags(int $contentId): Collection
    {
        $key = $this->getContentTagsKey($contentId);

        return $this->cache->tags(['tags', "content:{$contentId}"])->remember(
            $key,
            static::CACHE_TTL,
            fn() => Content::findOrFail($contentId)->tags
        );
    }

    /**
     * Get popular tags from cache or database.
     */
    public function getPopularTags(int $limit = 10): Collection
    {
        $key = $this->getPopularTagsKey($limit);

        return $this->cache->tags(['tags', 'popular'])->remember(
            $key,
            static::CACHE_TTL,
            fn() => Tag::orderByContentCount()->limit($limit)->get()
        );
    }

    /**
     * Invalidate tag cache.
     */
    public function invalidateTag(int $id): void
    {
        $this->cache->tags(['tags'])->forget($this->getTagKey($id));
    }

    /**
     * Invalidate content tags cache.
     */
    public function invalidateContentTags(int $contentId): void
    {
        $this->cache->tags(['tags', "content:{$contentId}"])->flush();
    }

    /**
     * Invalidate all tag-related caches.
     */
    public function invalidateAllTags(): void
    {
        $this->cache->tags(['tags'])->flush();
    }

    /**
     * Get cache key for a tag.
     */
    protected function getTagKey(int $id): string
    {
        return "tag:{$id}";
    }

    /**
     * Get cache key for content tags.
     */
    protected function getContentTagsKey(int $contentId): string
    {
        return "content:{$contentId}:tags";
    }

    /**
     * Get cache key for popular tags.
     */
    protected function getPopularTagsKey(int $limit): string
    {
        return "popular_tags:{$limit}";
    }
}
