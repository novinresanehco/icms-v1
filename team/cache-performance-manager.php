<?php

namespace App\Core\Performance;

class CacheManager implements CacheManagerInterface 
{
    private Cache $cache;
    private SecurityManager $security;
    private ValidationService $validator;
    private MetricsCollector $metrics;
    private array $config;

    public function __construct(
        Cache $cache,
        SecurityManager $security,
        ValidationService $validator,
        MetricsCollector $metrics,
        array $config
    ) {
        $this->cache = $cache;
        $this->security = $security;
        $this->validator = $validator;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $startTime = microtime(true);
        $cacheKey = $this->generateCacheKey($key);

        try {
            if ($this->cache->has($cacheKey)) {
                $value = $this->cache->get($cacheKey);
                if ($this->validateCachedData($value)) {
                    $this->metrics->incrementCacheHit($key);
                    return $value;
                }
            }

            $value = $callback();
            $this->validateDataForCaching($value);
            
            $ttl = $ttl ?? $this->config['default_ttl'];
            $this->cache->put($cacheKey, $value, $ttl);
            
            $this->metrics->incrementCacheMiss($key);
            return $value;

        } catch (\Exception $e) {
            $this->handleCacheFailure($key, $e);
            return $callback();
        } finally {
            $this->recordMetrics($key, microtime(true) - $startTime);
        }
    }

    public function tags(array $tags): TaggedCache
    {
        return new TaggedCache(
            $this->cache,
            $this->security,
            $this->validator,
            $this->metrics,
            $tags
        );
    }

    public function invalidate(string $key): void 
    {
        $cacheKey = $this->generateCacheKey($key);
        $this->cache->forget($cacheKey);
        $this->metrics->incrementCacheInvalidation($key);
    }

    public function invalidateTag(string $tag): void
    {
        $this->cache->tags([$tag])->flush();
        $this->metrics->incrementTagInvalidation($tag);
    }

    public function invalidatePattern(string $pattern): void
    {
        $keys = $this->cache->getKeysMatchingPattern($pattern);
        foreach ($keys as $key) {
            $this->invalidate($key);
        }
    }

    public function warmup(array $keys): void
    {
        foreach ($keys as $key => $callback) {
            if (!$this->cache->has($key)) {
                try {
                    $value = $callback();
                    $this->validateDataForCaching($value);
                    $this->cache->put($key, $value, $this->config['default_ttl']);
                } catch (\Exception $e) {
                    $this->handleCacheFailure($key, $e);
                }
            }
        }
    }

    private function generateCacheKey(string $key): string
    {
        return hash_hmac(
            'sha256',
            $key,
            $this->config['cache_key_salt']
        );
    }

    private function validateCachedData($data): bool
    {
        if (!$this->validator->validateCacheData($data)) {
            $this->invalidate($data);
            return false;
        }
        return true;
    }

    private function validateDataForCaching($data): void
    {
        if (!$this->validator->validateCacheableData($data)) {
            throw new InvalidCacheDataException();
        }
    }

    private function handleCacheFailure(string $key, \Exception $e): void
    {
        $this->metrics->incrementCacheFailure($key);
        
        Log::error('Cache operation failed', [
            'key' => $key,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($this->isRecoverableError($e)) {
            $this->invalidate($key);
        }
    }

    private function recordMetrics(string $key, float $duration): void
    {
        $this->metrics->recordCacheOperation([
            'key' => $key,
            'duration' => $duration,
            'memory' => memory_get_peak_usage(true)
        ]);
    }

    private function isRecoverableError(\Exception $e): bool
    {
        return !($e instanceof SecurityException);
    }
}

class TaggedCache implements CacheInterface
{
    private Cache $cache;
    private SecurityManager $security;
    private ValidationService $validator;
    private MetricsCollector $metrics;
    private array $tags;

    public function __construct(
        Cache $cache,
        SecurityManager $security,
        ValidationService $validator,
        MetricsCollector $metrics,
        array $tags
    ) {
        $this->cache = $cache;
        $this->security = $security;
        $this->validator = $validator;
        $this->metrics = $metrics;
        $this->tags = $tags;
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        return $this->cache
            ->tags($this->tags)
            ->remember($key, $ttl ?? 0, $callback);
    }

    public function invalidate(string $key): void
    {
        $this->cache
            ->tags($this->tags)
            ->forget($key);
    }

    public function flush(): void
    {
        $this->cache
            ->tags($this->tags)
            ->flush();
    }
}
