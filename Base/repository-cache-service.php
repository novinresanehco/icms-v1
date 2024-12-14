<?php

namespace App\Core\Cache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class RepositoryCacheService
{
    /**
     * Cache repository method result
     */
    public function remember(
        string $repository,
        string $method,
        array $arguments,
        \Closure $callback
    ): mixed {
        $tags = RepositoryCacheConfig::getTags($repository);
        $key = RepositoryCacheConfig::generateCacheKey($repository, $method, $arguments);
        $ttl = RepositoryCacheConfig::getTTL($repository);

        return Cache::tags($tags)->remember($key, $ttl, $callback);
    }

    /**
     * Clear cache for repository
     */
    public function clear(string $repository, ?Model $model = null): void
    {
        $tags = RepositoryCacheConfig::getTags($repository);
        
        if ($model) {
            $key = RepositoryCacheConfig::generateCacheKey(
                $repository,
                'find',
                [$model->getKey()]
            );
            Cache::tags($tags)->forget($key);
        } else {
            Cache::tags($tags)->flush();
        }
    }

    /**
     * Determine if result should be cached
     */
    public function shouldCache(mixed $result): bool
    {
        return $result instanceof Model ||
               $result instanceof Collection ||
               is_array($result);
    }

    /**
     * Get cache metadata
     */
    public function getCacheInfo(string $repository): array
    {
        return [
            'tags' => RepositoryCacheConfig::getTags($repository),
            'ttl' => RepositoryCacheConfig::getTTL($repository),
            'hit_ratio' => $this->calculateHitRatio($repository)
        ];
    }

    /**
     * Calculate cache hit ratio
     */
    protected function calculateHitRatio(string $repository): float
    {
        $stats = Cache::tags(RepositoryCacheConfig::getTags($repository))
            ->get('cache_stats', ['hits' => 0, 'misses' => 0]);
            
        $total = $stats['hits'] + $stats['misses'];
        
        return $total > 0 ? ($stats['hits'] / $total) * 100 : 0;
    }
}
