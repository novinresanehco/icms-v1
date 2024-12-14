<?php

namespace App\Core\Tag\Repository;

use App\Core\Tag\Models\Tag;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use App\Core\Tag\Contracts\TagCacheInterface;

class TagCacheRepository implements TagCacheInterface
{
    /**
     * Cache prefix for tag-related keys.
     */
    protected const CACHE_PREFIX = 'tag:';

    /**
     * Default cache duration in minutes.
     */
    protected const CACHE_DURATION = 60;

    /**
     * Cache tag for group operations.
     */
    protected const CACHE_TAG = 'tags';

    /**
     * Get a tag from cache or database.
     */
    public function remember(int $id, array $with = []): ?Tag
    {
        $key = $this->getCacheKey($id, $with);

        return Cache::tags([self::CACHE_TAG])->remember(
            $key,
            self::CACHE_DURATION,
            fn() => Tag::with($with)->find($id)
        );
    }

    /**
     * Get multiple tags from cache or database.
     */
    public function rememberMany(array $ids, array $with = []): Collection
    {
        $key = $this->getMultipleCacheKey($ids, $with);

        return Cache::tags([self::CACHE_TAG])->remember(
            $key,
            self::CACHE_DURATION,
            fn() => Tag::with($with)->findMany($ids)
        );
    }

    /**
     * Cache tag relationships.
     */
    public function rememberRelationships(int $id, string $relation): Collection
    {
        $key = $this->getRelationshipCacheKey($id, $relation);

        return Cache::tags([self::CACHE_TAG])->remember(
            $key,
            self::CACHE_DURATION,
            fn() => Tag::findOrFail($id)->$relation()->get()
        );
    }

    /**
     * Clear cache for a specific tag.
     */
    public function clearTagCache(int $id = null): void
    {
        if ($id) {
            $pattern = self::CACHE_PREFIX . $id . '*';
            $this->clearCacheByPattern($pattern);
        } else {
            Cache::tags([self::CACHE_TAG])->flush();
        }
    }

    /**
     * Clear all tag-related caches.
     */
    public function clearAllTagCaches(): void
    {
        Cache::tags([self::CACHE_TAG])->flush();
    }

    /**
     * Warm up cache for frequently accessed tags.
     */
    public function warmUp(array $ids = []): void
    {
        if (empty($ids)) {
            $ids = $this->getFrequentlyAccessedTagIds();
        }

        foreach ($ids as $id) {
            $this->remember($id, ['contents', 'metadata']);
        }
    }

    /**
     * Get cache key for a single tag.
     */
    protected function getCacheKey(int $id, array $with = []): string
    {
        $suffix = empty($with) ? '' : ':' . md5(serialize($with));
        return self::CACHE_PREFIX . $id . $suffix;
    }

    /**
     * Get cache key for multiple tags.
     */
    protected function getMultipleCacheKey(array $ids, array $with = []): string
    {
        $idsKey = implode(',', sort($ids));
        $suffix = empty($with) ? '' : ':' . md5(serialize($with));
        return self::CACHE_PREFIX . 'multiple:' . md5($idsKey) . $suffix;
    }

    /**
     * Get cache key for relationships.
     */
    protected function getRelationshipCacheKey(int $id, string $relation): string
    {
        return self::CACHE_PREFIX . $id . ':relation:' . $relation;
    }

    /**
     * Clear cache by pattern.
     */
    protected function clearCacheByPattern(string $pattern): void
    {
        $keys = Cache::get($pattern);
        if ($keys) {
            Cache::deleteMultiple($keys);
        }
    }

    /**
     * Get frequently accessed tag IDs.
     */
    protected function getFrequentlyAccessedTagIds(): array
    {
        return Tag::query()
            ->withCount('contents')
            ->orderByDesc('contents_count')
            ->limit(100)
            ->pluck('id')
            ->toArray();
    }
}
