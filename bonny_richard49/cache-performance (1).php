<?php

namespace App\Core\Cache;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use App\Core\Security\SecurityManager;
use App\Core\Monitoring\PerformanceMonitor;

class CacheManager implements CacheInterface 
{
    private SecurityManager $security;
    private PerformanceMonitor $monitor;
    private array $config;
    private array $metrics = [];

    public function __construct(
        SecurityManager $security,
        PerformanceMonitor $monitor,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->config = $config;
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $this->validateCacheOperation($key);
        
        $this->monitor->startOperation("cache:remember:{$key}");
        
        try {
            $value = Cache::tags($this->getTags($key))
                ->remember($this->getSecureKey($key), $ttl, function() use ($callback) {
                    $result = $callback();
                    $this->validateCacheData($result);
                    return $this->encryptIfNeeded($result);
                });

            $this->recordMetrics($key, true);
            return $this->decryptIfNeeded($value);
            
        } catch (\Throwable $e) {
            $this->handleCacheFailure($key, $e);
            throw $e;
            
        } finally {
            $this->monitor->endOperation("cache:remember:{$key}");
        }
    }

    public function put(string $key, mixed $value, int $ttl): bool
    {
        $this->validateCacheOperation($key);
        
        try {
            $this->validateCacheData($value);
            $encrypted = $this->encryptIfNeeded($value);
            
            return Cache::tags($this->getTags($key))
                ->put($this->getSecureKey($key), $encrypted, $ttl);
                
        } catch (\Throwable $e) {
            $this->handleCacheFailure($key, $e);
            return false;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->validateCacheOperation($key);
        
        try {
            $value = Cache::tags($this->getTags($key))
                ->get($this->getSecureKey($key));
                
            if ($value === null) {
                $this->recordMetrics($key, false);
                return $default;
            }

            $this->recordMetrics($key, true);
            return $this->decryptIfNeeded($value);
            
        } catch (\Throwable $e) {
            $this->handleCacheFailure($key, $e);
            return $default;
        }
    }

    public function forget(string $key): bool
    {
        $this->validateCacheOperation($key);
        
        try {
            return Cache::tags($this->getTags($key))
                ->forget($this->getSecureKey($key));
                
        } catch (\Throwable $e) {
            $this->handleCacheFailure($key, $e);
            return false;
        }
    }

    public function tags(array $tags): static
    {
        foreach ($tags as $tag) {
            $this->validateTag($tag);
        }
        
        return Cache::tags($tags);
    }

    public function flush(): bool
    {
        $this->validateCacheOperation('flush');
        
        try {
            if (config('cache.default') === 'redis') {
                return $this->flushRedis();
            }
            return Cache::flush();
            
        } catch (\Throwable $e) {
            $this->handleCacheFailure('flush', $e);
            return false;
        }
    }

    private function flushRedis(): bool
    {
        $keys = Redis::keys($this->config['redis_prefix'] . '*');
        foreach ($keys as $key) {
            Redis::del($key);
        }
        return true;
    }

    private function getSecureKey(string $key): string
    {
        return hash_hmac('sha256', $key, $this->config['cache_key_salt']);
    }

    private function validateCacheOperation(string $key): void
    {
        $this->security->validateOperation('cache.access', ['key' => $key]);
        
        if ($this->isRateLimited($key)) {
            throw new CacheException('Rate limit exceeded for cache operations');
        }
    }

    private function validateCacheData(mixed $data): void
    {
        if (!is_string($data) && !is_array($data) && !is_null($data)) {
            throw new CacheException('Invalid data type for caching');
        }

        if (is_array($data)) {
            array_walk_recursive($data, function($item) {
                if (is_object($item) && !$item instanceof \Serializable) {
                    throw new CacheException('Cannot cache non-serializable objects');
                }
            });
        }
    }

    private function validateTag(string $tag): void
    {
        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $tag)) {
            throw new CacheException('Invalid cache tag format');
        }
    }

    private function encryptIfNeeded(mixed $data): mixed
    {
        if ($this->shouldEncrypt($data)) {
            return $this->security->encrypt($data);
        }
        return $data;
    }

    private function decryptIfNeeded(mixed $data): mixed
    {
        if ($this->shouldEncrypt($data)) {
            return $this->security->decrypt($data);
        }
        return $data;
    }

    private function shouldEncrypt(mixed $data): bool
    {
        return is_array($data) || 
            (is_string($data) && strlen($data) > $this->config['encryption_threshold']);
    }

    private function getTags(string $key): array
    {
        $parts = explode(':', $key);
        return array_filter($parts, function($part) {
            return strlen($part) > 0 && strlen($part) <= 32;
        });
    }

    private function isRateLimited(string $key): bool
    {
        $limit = $this->config['rate_limit'] ?? 1000;
        $window = $this->config['rate_window'] ?? 60;
        
        $currentCount = Cache::get("ratelimit:cache:{$key}", 0);
        if ($currentCount >= $limit) {
            return true;
        }
        
        Cache::increment("ratelimit:cache:{$key}");
        Cache::expire("ratelimit:cache:{$key}", $window);
        
        return false;
    }

    private function handleCacheFailure(string $key, \Throwable $e): void
    {
        $this->monitor->logError('cache_failure', [
            'key' => $key,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($this->isRedisFailure($e)) {
            $this->switchToFallbackDriver();
        }
    }

    private function isRedisFailure(\Throwable $e): bool
    {
        return $e instanceof \RedisException || 
            strpos($e->getMessage(), 'redis') !== false;
    }

    private function switchToFallbackDriver(): void
    {
        config(['cache.default' => $this->config['fallback_driver']]);
        $this->monitor->logWarning('cache_driver_fallback', [
            'from' => 'redis',
            'to' => $this->config['fallback_driver']
        ]);
    }

    private function recordMetrics(string $key, bool $hit): void
    {
        $this->metrics[$key] = ($this->metrics[$key] ?? ['hits' => 0, 'misses' => 0]);
        $hit ? $this->metrics[$key]['hits']++ : $this->metrics[$key]['misses']++;
        
        if (count($this->metrics) >= 1000) {
            $this->flushMetrics();
        }
    }

    private function flushMetrics(): void
    {
        $this->monitor->recordMetrics('cache', $this->metrics);
        $this->metrics = [];
    }
}
