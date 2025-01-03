<?php

namespace App\Core\Cache;

class CacheManager implements CacheManagerInterface
{
    private CacheStore $store;
    private SecurityManager $security;
    private MetricsCollector $metrics;
    private CacheConfig $config;
    
    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $monitorId = $this->metrics->startOperation('cache.remember');
        
        try {
            if ($value = $this->get($key)) {
                $this->metrics->recordHit($monitorId);
                return $value;
            }

            $value = $callback();
            $this->set($key, $value, $ttl);
            
            $this->metrics->recordMiss($monitorId);
            return $value;
            
        } catch (\Exception $e) {
            $this->metrics->recordFailure($monitorId, $e);
            throw $e;
        }
    }

    public function get(string $key): mixed
    {
        $value = $this->store->get($this->getSecureKey($key));
        return $value ? $this->security->decrypt($value) : null;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        $encrypted = $this->security->encrypt(serialize($value));
        $this->store->put(
            $this->getSecureKey($key),
            $encrypted,
            $ttl ?? $this->config->get('cache.ttl')
        );
    }

    public function invalidate(string $key): void
    {
        $this->store->forget($this->getSecureKey($key));
    }

    public function invalidatePattern(string $pattern): void
    {
        foreach ($this->store->getByPattern($pattern) as $key) {
            $this->invalidate($key);
        }
    }

    private function getSecureKey(string $key): string
    {
        return hash_hmac('sha256', $key, $this->config->get('cache.key'));
    }
}

class RateLimitManager implements RateLimitManagerInterface
{
    private CacheManager $cache;
    private SecurityManager $security;
    private RateLimitConfig $config;
    
    public function attempt(string $key, int $maxAttempts, int $decay): bool
    {
        $attempts = (int)$this->cache->remember(
            $this->getRateLimitKey($key),
            fn() => 0,
            $decay
        );

        if ($attempts >= $maxAttempts) {
            $this->security->logRateLimitExceeded($key);
            return false;
        }

        $this->cache->set(
            $this->getRateLimitKey($key),
            $attempts + 1,
            $decay
        );

        return true;
    }

    public function remaining(string $key, int $maxAttempts): int
    {
        $attempts = (int)$this->cache->get($this->getRateLimitKey($key));
        return max(0, $maxAttempts - $attempts);
    }

    public function reset(string $key): void
    {
        $this->cache->invalidate($this->getRateLimitKey($key));
    }

    private function getRateLimitKey(string $key): string
    {
        return "rate_limit:{$key}";
    }
}

class CacheWarmer implements CacheWarmerInterface
{
    private CacheManager $cache;
    private ContentManager $content;
    private SecurityManager $security;
    private MetricsCollector $metrics;
    
    public function warmCache(): void
    {
        $monitorId = $this->metrics->startOperation('cache.warm');
        
        try {
            // Warm critical data
            $this->warmCriticalData();
            
            // Warm frequently accessed data
            $this->warmFrequentData();
            
            // Warm configuration data
            $this->warmConfigData();
            
            $this->metrics->recordSuccess($monitorId);
            
        } catch (\Exception $e) {
            $this->metrics->recordFailure($monitorId, $e);
            $this->security->handleCacheWarmingFailure($e);
            throw $e;
        }
    }

    private function warmCriticalData(): void
    {
        // Cache critical content
        $this->content->getCriticalContent()->each(
            fn($content) => $this->cache->remember(
                "content:{$content->id}",
                fn() => $content
            )
        );

        // Cache security data
        $this->security->getCriticalData()->each(
            fn($data) => $this->cache->remember(
                "security:{$data->id}",
                fn() => $data
            )
        );
    }

    private function warmFrequentData(): void
    {
        // Cache frequently accessed data based on metrics
        $frequentKeys = $this->metrics->getFrequentlyAccessed();
        
        foreach ($frequentKeys as $key) {
            $this->cache->remember(
                $key,
                fn() => $this->content->get($key)
            );
        }
    }

    private function warmConfigData(): void
    {
        // Cache configuration data
        config()->all()->each(
            fn($value, $key) => $this->cache->remember(
                "config:{$key}",
                fn() => $value
            )
        );
    }
}
