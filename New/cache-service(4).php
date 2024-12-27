<?php

namespace App\Core\Cache;

class CacheService implements CacheInterface
{
    private SecurityManager $security;
    private CacheStore $store;
    private int $ttl;

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $cacheKey = $this->generateKey($key);
        
        if ($cached = $this->get($cacheKey)) {
            return $this->security->decrypt($cached);
        }

        $value = $callback();
        $this->set($cacheKey, $this->security->encrypt($value), $ttl ?? $this->ttl);
        
        return $value;
    }

    public function get(string $key): mixed
    {
        return $this->store->get($this->generateKey($key));
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        $this->store->put(
            $this->generateKey($key),
            $value,
            $ttl ?? $this->ttl
        );
    }

    public function has(string $key): bool
    {
        return $this->store->has($this->generateKey($key));
    }

    public function forget(string $key): void
    {
        $this->store->forget($this->generateKey($key));
    }

    private function generateKey(string $key): string
    {
        return hash_hmac('sha256', $key, config('app.key'));
    }
}

class CacheStore
{
    private RedisConnection $redis;
    private BackupStore $backup;
    private MetricsCollector $metrics;

    public function get(string $key): mixed
    {
        try {
            $start = microtime(true);
            $value = $this->redis->get($key);
            $this->recordMetrics('get', microtime(true) - $start);
            return $value;
        } catch (\Exception $e) {
            return $this->backup->get($key);
        }
    }

    public function put(string $key, mixed $value, int $ttl): void
    {
        try {
            $start = microtime(true);
            $this->redis->setex($key, $ttl, $value);
            $this->recordMetrics('set', microtime(true) - $start);
            $this->backup->put($key, $value, $ttl);
        } catch (\Exception $e) {
            $this->backup->put($key, $value, $ttl);
        }
    }

    public function has(string $key): bool
    {
        try {
            return $this->redis->exists($key);
        } catch (\Exception $e) {
            return $this->backup->has($key);
        }
    }

    public function forget(string $key): void
    {
        try {
            $this->redis->del($key);
            $this->backup->forget($key);
        } catch (\Exception $e) {
            $this->backup->forget($key);
        }
    }

    private function recordMetrics(string $operation, float $duration): void
    {
        $this->metrics->record("cache_{$operation}", $duration);
    }
}
