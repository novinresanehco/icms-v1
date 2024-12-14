<?php

namespace App\Core\Cache;

use App\Core\Security\SecurityContext;
use App\Core\Security\CoreSecurityManager;
use Illuminate\Support\Facades\{Cache, Redis};
use Psr\SimpleCache\CacheInterface;

class CachePerformanceManager implements CacheManagerInterface
{
    private CoreSecurityManager $security;
    private CacheInterface $cache;
    private MetricsCollector $metrics;
    private array $config;
    
    private const CACHE_VERSION = 'v1';
    private const MAX_LOCK_WAIT = 5;

    public function __construct(
        CoreSecurityManager $security,
        CacheInterface $cache,
        MetricsCollector $metrics,
        array $config
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $cacheKey = $this->buildKey($key);
        $lockKey = "lock:{$cacheKey}";
        
        if ($value = $this->get($cacheKey)) {
            $this->metrics->incrementCacheHit($key);
            return $value;
        }

        $this->metrics->incrementCacheMiss($key);
        
        return retry(3, function() use ($cacheKey, $lockKey, $callback, $ttl) {
            return Redis::throttle($lockKey)
                ->block(self::MAX_LOCK_WAIT)
                ->allow(1)
                ->every(1)
                ->then(
                    function() use ($cacheKey, $callback, $ttl) {
                        $value = $callback();
                        $this->put($cacheKey, $value, $ttl);
                        return $value;
                    },
                    function() {
                        throw new CacheLockException('Failed to acquire cache lock');
                    }
                );
        });
    }

    public function rememberForever(string $key, callable $callback): mixed
    {
        return $this->remember($key, $callback, null);
    }

    public function tags(array $tags): TaggedCache
    {
        return new TaggedCache(
            $this->security,
            $this->cache,
            $this->metrics,
            $tags,
            $this->config
        );
    }

    public function invalidate(string $key): bool
    {
        $cacheKey = $this->buildKey($key);
        $this->metrics->incrementCacheInvalidation($key);
        return $this->cache->delete($cacheKey);
    }

    public function invalidateTag(string $tag): bool
    {
        $this->metrics->incrementTagInvalidation($tag);
        return Cache::tags($tag)->flush();
    }

    public function invalidateTags(array $tags): bool
    {
        foreach ($tags as $tag) {
            $this->metrics->incrementTagInvalidation($tag);
        }
        return Cache::tags($tags)->flush();
    }

    public function warmup(array $keys, callable $callback): void
    {
        foreach ($keys as $key) {
            if (!$this->has($key)) {
                $this->remember($key, fn() => $callback($key));
            }
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $cacheKey = $this->buildKey($key);
        $value = $this->cache->get($cacheKey, $default);
        
        if ($value !== null) {
            $this->metrics->incrementCacheHit($key);
        } else {
            $this->metrics->incrementCacheMiss($key);
        }
        
        return $value;
    }

    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        $cacheKey = $this->buildKey($key);
        $ttl = $ttl ?? $this->config['default_ttl'] ?? 3600;
        
        $this->metrics->incrementCacheWrite($key);
        return $this->cache->set($cacheKey, $value, $ttl);
    }

    public function has(string $key): bool
    {
        return $this->cache->has($this->buildKey($key));
    }

    public function increment(string $key, int $value = 1): int
    {
        $cacheKey = $this->buildKey($key);
        return $this->cache->increment($cacheKey, $value);
    }

    public function decrement(string $key, int $value = 1): int
    {
        $cacheKey = $this->buildKey($key);
        return $this->cache->decrement($cacheKey, $value);
    }

    public function forever(string $key, mixed $value): bool
    {
        return $this->put($key, $value, null);
    }

    public function forget(string $key): bool
    {
        return $this->invalidate($key);
    }

    public function flush(): bool
    {
        $this->metrics->incrementCacheFlush();
        return $this->cache->clear();
    }

    public function getMultiple(array $keys, mixed $default = null): iterable
    {
        $cacheKeys = array_map(fn($key) => $this->buildKey($key), $keys);
        return $this->cache->getMultiple($cacheKeys, $default);
    }

    public function setMultiple(array $values, ?int $ttl = null): bool
    {
        $cacheValues = [];
        foreach ($values as $key => $value) {
            $cacheValues[$this->buildKey($key)] = $value;
        }
        return $this->cache->setMultiple($cacheValues, $ttl);
    }

    public function deleteMultiple(array $keys): bool
    {
        $cacheKeys = array_map(fn($key) => $this->buildKey($key), $keys);
        return $this->cache->deleteMultiple($cacheKeys);
    }

    private function buildKey(string $key): string
    {
        return sprintf(
            '%s:%s:%s',
            self::CACHE_VERSION,
            $this->config['prefix'] ?? 'app',
            $key
        );
    }

    public function optimizeCache(): void
    {
        $this->security->executeCriticalOperation(
            new CacheOperation('optimize'),
            new SecurityContext(['system' => true]),
            function() {
                $this->removeExpiredKeys();
                $this->compactCache();
                $this->updateCacheStats();
            }
        );
    }

    private function removeExpiredKeys(): void
    {
        Redis::connection()->eval(
            "local keys = redis.call('keys', ARGV[1]) " .
            "for i=1,#keys,5000 do " .
            "redis.call('del', unpack(keys, i, math.min(i+4999, #keys))) " .
            "end",
            0,
            $this->config['prefix'] . ':*'
        );
    }

    private function compactCache(): void
    {
        if ($this->config['driver'] === 'redis') {
            Redis::connection()->bgrewriteaof();
        }
    }

    private function updateCacheStats(): void
    {
        $this->metrics->recordCacheStats([
            'memory_usage' => Redis::connection()->info()['used_memory'],
            'hit_rate' => $this->metrics->getCacheHitRate(),
            'keys_count' => Redis::connection()->dbsize(),
        ]);
    }
}
