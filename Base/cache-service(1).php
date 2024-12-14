<?php

namespace App\Core\Services\Cache;

class CacheManager extends BaseService
{
    private CacheStore $store;
    private CacheConfig $cacheConfig;
    private MetricsCollector $metrics;

    protected function boot(): void
    {
        $this->store = $this->container->make(CacheStore::class);
        $this->cacheConfig = new CacheConfig($this->config);
        $this->metrics = new MetricsCollector();
    }

    public function remember(string $key, Closure $callback, ?int $ttl = null): mixed
    {
        $ttl = $ttl ?? $this->cacheConfig->getDefaultTTL();
        
        if ($this->shouldBypassCache($key)) {
            return $callback();
        }

        $value = $this->store->get($key);
        
        if ($value !== null) {
            $this->metrics->recordHit($key);
            return $value;
        }

        $this->metrics->recordMiss($key);
        $value = $callback();
        
        $this->store->put($key, $value, $ttl);
        return $value;
    }

    public function tags(array $tags): TaggedCache
    {
        return new TaggedCache($this->store, $tags, $this->cacheConfig);
    }

    protected function shouldBypassCache(string $key): bool
    {
        if (!$this->cacheConfig->isCachingEnabled()) {
            return true;
        }

        $metrics = $this->metrics->getMetrics($key);
        $hitRate = $metrics->getHitRate();

        return $hitRate < $this->cacheConfig->getMinimumHitRate();
    }

    protected function getRequiredConfig(): array
    {
        return [
            'enabled',
            'default_ttl',
            'minimum_hit_rate',
            'store'
        ];
    }

    protected function getMetrics(): array
    {
        return [
            'hit_rate' => $this->metrics->getGlobalHitRate(),
            'total_hits' => $this->metrics->getTotalHits(),
            'total_misses' => $this->metrics->getTotalMisses(),
            'memory_usage' => $this->store->getMemoryUsage()
        ];
    }
}
