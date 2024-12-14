<?php

namespace App\Core\Repository;

use App\Models\Cache;
use App\Core\Exceptions\CacheRepositoryException;

class CacheRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return Cache::class;
    }

    /**
     * Get cache statistics
     */
    public function getCacheStats(): array
    {
        try {
            $totalSize = 0;
            $totalItems = 0;
            $tags = [];

            foreach (Cache::tags() as $tag) {
                $items = Cache::tag($tag)->getKeys();
                $size = 0;

                foreach ($items as $key) {
                    $value = Cache::get($key);
                    $size += strlen(serialize($value));
                }

                $tags[$tag] = [
                    'items' => count($items),
                    'size' => $size
                ];

                $totalSize += $size;
                $totalItems += count($items);
            }

            return [
                'total_items' => $totalItems,
                'total_size' => $totalSize,
                'tags' => $tags,
                'hit_rate' => $this->calculateHitRate()
            ];
        } catch (\Exception $e) {
            throw new CacheRepositoryException(
                "Failed to get cache stats: {$e->getMessage()}"
            );
        }
    }

    /**
     * Clear cache by tags
     */
    public function clearByTags(array $tags): void
    {
        try {
            Cache::tags($tags)->flush();
        } catch (\Exception $e) {
            throw new CacheRepositoryException(
                "Failed to clear cache by tags: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get cache keys by pattern
     */
    public function getKeysByPattern(string $pattern): array
    {
        try {
            return Cache::getRedis()->keys($pattern);
        } catch (\Exception $e) {
            throw new CacheRepositoryException(
                "Failed to get cache keys: {$e->getMessage()}"
            );
        }
    }

    /**
     * Calculate cache hit rate
     */
    private function calculateHitRate(): float
    {
        $hits = Cache::get('cache_hits', 0);
        $misses = Cache::get('cache_misses', 0);
        $total = $hits + $misses;

        return $total > 0 ? ($hits / $total) * 100 : 0;
    }

    /**
     * Warm up cache
     */
    public function warmUp(array $keys): void
    {
        try {
            foreach ($keys as $key => $callback) {
                if (!Cache::has($key)) {
                    Cache::put($key, $callback(), $this->cacheTime);
                }
            }
        } catch (\Exception $e) {
            throw new CacheRepositoryException(
                "Failed to warm up cache: {$e->getMessage()}"
            );
        }
    }
}
