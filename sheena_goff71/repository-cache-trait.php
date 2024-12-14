<?php

namespace App\Core\Repository\Traits;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

trait HasCache
{
    /**
     * Cache key prefix for the repository
     *
     * @var string
     */
    protected string $cachePrefix = 'repository_';

    /**
     * Cache duration in seconds
     *
     * @var int
     */
    protected int $cacheDuration = 3600; // 1 hour

    /**
     * Cache tags
     *
     * @var array
     */
    protected array $cacheTags = [];

    /**
     * Whether caching is enabled
     *
     * @var bool
     */
    protected bool $cacheEnabled = true;

    /**
     * Get cache instance with tags
     *
     * @return \Illuminate\Cache\TaggedCache|\Illuminate\Cache\Repository
     */
    protected function getCacheInstance()
    {
        $cache = Cache::store(Config::get('cache.default'));
        
        return empty($this->cacheTags) 
            ? $cache 
            : $cache->tags($this->getCacheTags());
    }

    /**
     * Get cache key
     *
     * @param string $key
     * @return string
     */
    protected function getCacheKey(string $key): string
    {
        return $this->cachePrefix . class_basename($this->model) . '_' . $key;
    }

    /**
     * Cache the result of a callback
     *
     * @param string $key
     * @param callable $callback
     * @return mixed
     */
    protected function cacheResult(string $key, callable $callback): mixed
    {
        if (!$this->cacheEnabled) {
            return $callback();
        }

        $cacheKey = $this->getCacheKey($key);

        return $this->getCacheInstance()->remember(
            $cacheKey,
            $this->cacheDuration,
            $callback
        );
    }

    /**
     * Clear the cache
     *
     * @return bool
     */
    protected function clearCache(): bool
    {
        if (!$this->cacheEnabled) {
            return true;
        }

        if (empty($this->cacheTags)) {
            return Cache::store(Config::get('cache.default'))->flush();
        }

        return $this->getCacheInstance()->flush();
    }

    /**
     * Set cache tags
     *
     * @param array $tags
     * @return self
     */
    public function setCacheTags(array $tags): self
    {
        $this->cacheTags = $tags;
        return $this;
    }

    /**
     * Get cache tags
     *
     * @return array
     */
    protected function getCacheTags(): array
    {
        return array_merge(
            [$this->cachePrefix . class_basename($this->model)],
            $this->cacheTags
        );
    }

    /**
     * Set cache duration
     *
     * @param int $seconds
     * @return self
     */
    public function setCacheDuration(int $seconds): self
    {
        $this->cacheDuration = $seconds;
        return $this;
    }

    /**
     * Enable caching
     *
     * @return self
     */
    public function enableCache(): self
    {
        $this->cacheEnabled = true;
        return $this;
    }

    /**
     * Disable caching
     *
     * @return self
     */
    public function disableCache(): self
    {
        $this->cacheEnabled = false;
        return $this;
    }
}
