<?php

namespace App\Core\Cache;

use Illuminate\Support\Facades\{Cache, Redis};
use App\Core\Contracts\CacheManagerInterface;
use App\Core\Security\SecurityContext;

class CacheManager implements CacheManagerInterface
{
    private string $prefix;
    private int $defaultTtl;
    private MetricsCollector $metrics;

    public function __construct(
        string $prefix,
        int $defaultTtl,
        MetricsCollector $metrics
    ) {
        $this->prefix = $prefix;
        $this->defaultTtl = $defaultTtl;
        $this->metrics = $metrics;
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $cacheKey = $this->getCacheKey($key);
        $startTime = microtime(true);

        try {
            if ($value = $this->get($key)) {
                $this->recordHit($key, $startTime);
                return $value;
            }

            $value = $callback();
            $this->set($key, $value, $ttl);
            $this->recordMiss($key, $startTime);

            return $value;
        } catch (\Exception $e) {
            $this->recordError($key, $e);
            throw $e;
        }
    }

    public function rememberForever(string $key, callable $callback): mixed
    {
        return $this->remember($key, $callback, null);
    }

    public function tags(array $tags): static
    {
        return new TaggedCache($this, $tags);
    }

    public function flush(): bool
    {
        return Cache::flush();
    }

    private function get(string $key): mixed
    {
        return Cache::get($this->getCacheKey($key));
    }

    private function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        return Cache::put(
            $this->getCacheKey($key),
            $value,
            $ttl ?? $this->defaultTtl
        );
    }

    private function getCacheKey(string $key): string
    {
        return sprintf('%s:%s', $this->prefix, $key);
    }

    private function recordHit(string $key, float $startTime): void
    {
        $this->metrics->increment('cache.hits');
        $this->metrics->timing('cache.hit_time', microtime(true) - $startTime);
    }

    private function recordMiss(string $key, float $startTime): void
    {
        $this->metrics->increment('cache.misses');
        $this->metrics->timing('cache.miss_time', microtime(true) - $startTime);
    }

    private function recordError(string $key, \Exception $e): void
    {
        $this->metrics->increment('cache.errors');
    }
}

class TaggedCache
{
    private CacheManager $cache;
    private array $tags;

    public function __construct(CacheManager $cache, array $tags)
    {
        $this->cache = $cache;
        $this->tags = $tags;
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        return Cache::tags($this->tags)->remember(
            $key,
            $ttl ?? $this->cache->defaultTtl,
            $callback
        );
    }

    public function flush(): bool
    {
        return Cache::tags($this->tags)->flush();
    }
}

class QueryCache
{
    private CacheManager $cache;
    private string $prefix = 'query';

    public function __construct(CacheManager $cache)
    {
        $this->cache = $cache;
    }

    public function getQueryResult(string $sql, array $bindings, callable $callback): mixed
    {
        $key = $this->generateQueryKey($sql, $bindings);

        return $this->cache->remember($key, $callback);
    }

    private function generateQueryKey(string $sql, array $bindings): string
    {
        $normalized = preg_replace('/\s+/', ' ', $sql);
        return md5($normalized . serialize($bindings));
    }
}

class ContentCache
{
    private CacheManager $cache;
    private array $contentTags = ['content'];

    public function __construct(CacheManager $cache)
    {
        $this->cache = $cache;
    }

    public function rememberContent(int $id, callable $callback): mixed
    {
        return $this->cache
            ->tags($this->contentTags)
            ->remember("content.$id", $callback);
    }

    public function invalidateContent(int $id): void
    {
        $this->cache
            ->tags($this->contentTags)
            ->flush();
    }
}

class PerformanceOptimizer
{
    private QueryCache $queryCache;
    private ContentCache $contentCache;
    private MetricsCollector $metrics;

    public function __construct(
        QueryCache $queryCache,
        ContentCache $contentCache,
        MetricsCollector $metrics
    ) {
        $this->queryCache = $queryCache;
        $this->contentCache = $contentCache;
        $this->metrics = $metrics;
    }

    public function optimizeQuery(string $sql, array $bindings, callable $callback): mixed
    {
        $startTime = microtime(true);

        try {
            $result = $this->queryCache->getQueryResult($sql, $bindings, $callback);
            $this->recordQueryMetrics($sql, $startTime);
            return $result;
        } catch (\Exception $e) {
            $this->recordQueryError($sql, $e);
            throw $e;
        }
    }

    private function recordQueryMetrics(string $sql, float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        $this->metrics->timing('query.duration', $duration);
        
        if ($duration > 1.0) {
            $this->metrics->increment('query.slow');
        }
    }

    private function recordQueryError(string $sql, \Exception $e): void
    {
        $this->metrics->increment('query.errors');
    }
}
