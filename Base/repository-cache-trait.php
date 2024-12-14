<?php

namespace App\Core\Cache;

use Illuminate\Database\Eloquent\Model;

trait CacheableRepository
{
    protected RepositoryCacheService $cacheService;

    /**
     * Initialize cache service
     */
    protected function initializeCache(): void
    {
        $this->cacheService = app(RepositoryCacheService::class);
    }

    /**
     * Execute method with caching
     */
    protected function executeWithCache(string $method, array $arguments, \Closure $callback): mixed
    {
        if (!isset($this->cacheService)) {
            $this->initializeCache();
        }

        return $this->cacheService->remember(
            $this->getRepositoryName(),
            $method,
            $arguments,
            function () use ($callback) {
                $result = $callback();
                
                if (!$this->cacheService->shouldCache($result)) {
                    return $result;
                }

                $this->updateCacheStats('hits');
                return $result;
            }
        );
    }

    /**
     * Clear repository cache
     */
    protected function clearCache(?Model $model = null): void
    {
        if (!isset($this->cacheService)) {
            $this->initializeCache();
        }

        $this->cacheService->clear($this->getRepositoryName(), $model);
    }

    /**
     * Get repository name for cache key
     */
    protected function getRepositoryName(): string
    {
        return strtolower(class_basename($this));
    }

    /**
     * Update cache statistics
     */
    protected function updateCacheStats(string $type): void
    {
        $key = sprintf('cache_stats_%s', $this->getRepositoryName());
        $stats = cache()->get($key, ['hits' => 0, 'misses' => 0]);
        $stats[$type]++;
        cache()->forever($key, $stats);
    }
}
