<?php

namespace App\Core\Performance;

use Illuminate\Support\Facades\{Cache, DB, Log};
use Illuminate\Contracts\Cache\Repository as CacheContract;

class PerformanceManager
{
    private CacheContract $cache;
    private ValidationService $validator;
    private MetricsCollector $metrics;
    private array $config;

    public function __construct(
        CacheContract $cache,
        ValidationService $validator,
        MetricsCollector $metrics,
        array $config
    ) {
        $this->cache = $cache;
        $this->validator = $validator;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function withCaching(string $key, callable $operation): mixed
    {
        $start = microtime(true);
        
        try {
            if ($this->validator->isValidCacheKey($key)) {
                if ($cached = $this->getFromCache($key)) {
                    $this->metrics->recordCacheHit($key);
                    return $cached;
                }
            }

            $result = $operation();
            
            if ($this->validator->isValidCacheKey($key)) {
                $this->storeInCache($key, $result);
            }
            
            $this->metrics->recordCacheMiss($key);
            return $result;
            
        } finally {
            $this->metrics->recordOperationTime(
                $key, 
                microtime(true) - $start
            );
        }
    }

    public function invalidate(array|string $keys): void
    {
        $keys = (array)$keys;
        
        foreach ($keys as $key) {
            if ($this->validator->isValidCacheKey($key)) {
                $this->cache->forget($key);
                $this->metrics->recordCacheInvalidation($key);
            }
        }
    }

    private function getFromCache(string $key): mixed
    {
        try {
            return $this->cache->get($key);
        } catch (\Exception $e) {
            Log::error('Cache retrieval failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function storeInCache(string $key, mixed $value): void
    {
        try {
            $ttl = $this->config['cache_ttl'] ?? 3600;
            $this->cache->put($key, $value, $ttl);
        } catch (\Exception $e) {
            Log::error('Cache storage failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
        }
    }
}

class CacheManager
{
    private CacheContract $cache;
    private ValidationService $validator;
    private array $config;

    public function __construct(
        CacheContract $cache,
        ValidationService $validator,
        array $config
    ) {
        $this->cache = $cache;
        $this->validator = $validator;
        $this->config = $config;
    }

    public function remember(string $key, callable $callback): mixed
    {
        $this->validator->validateCacheKey($key);
        
        return $this->cache->remember(
            $key,
            $this->config['cache_ttl'] ?? 3600,
            $callback
        );
    }

    public function rememberForever(string $key, callable $callback): mixed
    {
        $this->validator->validateCacheKey($key);
        return $this->cache->rememberForever($key, $callback);
    }

    public function forget(string $key): bool
    {
        $this->validator->validateCacheKey($key);
        return $this->cache->forget($key);
    }

    public function tags(array $tags): CacheContract
    {
        array_walk($tags, [$this->validator, 'validateCacheTag']);
        return $this->cache->tags($tags);
    }
}

class MetricsCollector
{
    private array $metrics = [];

    public function recordCacheHit(string $key): void
    {
        $this->incrementMetric('cache_hits', $key);
    }

    public function recordCacheMiss(string $key): void
    {
        $this->incrementMetric('cache_misses', $key);
    }

    public function recordCacheInvalidation(string $key): void
    {
        $this->incrementMetric('cache_invalidations', $key);
    }

    public function recordOperationTime(string $key, float $time): void
    {
        $this->metrics['operation_times'][$key][] = $time;
    }

    private function incrementMetric(string $type, string $key): void
    {
        if (!isset($this->metrics[$type][$key])) {
            $this->metrics[$type][$key] = 0;
        }
        $this->metrics[$type][$key]++;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }
}

class ValidationService
{
    private const MAX_KEY_LENGTH = 250;
    private const KEY_PATTERN = '/^[a-zA-Z0-9_.-]+$/';

    public function validateCacheKey(string $key): void
    {
        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw new \InvalidArgumentException('Cache key too long');
        }

        if (!preg_match(self::KEY_PATTERN, $key)) {
            throw new \InvalidArgumentException('Invalid cache key format');
        }
    }

    public function isValidCacheKey(string $key): bool
    {
        try {
            $this->validateCacheKey($key);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function validateCacheTag(string $tag): void
    {
        if (!preg_match(self::KEY_PATTERN, $tag)) {
            throw new \InvalidArgumentException('Invalid cache tag format');
        }
    }
}
