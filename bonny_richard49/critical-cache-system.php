// app/Core/Cache/CacheManager.php
<?php

namespace App\Core\Cache;

use App\Core\Security\SecurityKernel;
use App\Core\Monitoring\MetricsCollector;

class CacheManager implements CacheInterface 
{
    private SecurityKernel $security;
    private MetricsCollector $metrics;
    private array $config;
    private array $stores;

    public function executeOperation(string $key, callable $operation): mixed
    {
        $startTime = microtime(true);
        
        try {
            // Pre-execution validation
            $this->validateCacheOperation($key);
            
            // Execute operation with monitoring
            $result = $this->security->executeSecure(function() use ($operation) {
                return $operation();
            });
            
            // Record metrics
            $this->recordMetrics('success', $key, $startTime);
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->handleCacheFailure($e, $key, $startTime);
            throw new CacheException('Cache operation failed', 0, $e);
        }
    }

    public function get(string $key, $default = null): mixed
    {
        return $this->executeOperation($key, function() use ($key, $default) {
            foreach ($this->getStores($key) as $store) {
                if ($value = $store->get($key)) {
                    $this->recordHit($store, $key);
                    return $value;
                }
            }

            $this->recordMiss($key);
            return $default;
        });
    }

    public function set(string $key, $value, $ttl = null): bool
    {
        return $this->executeOperation($key, function() use ($key, $value, $ttl) {
            $success = true;
            
            foreach ($this->getStores($key) as $store) {
                if (!$store->set($key, $value, $ttl)) {
                    $success = false;
                    $this->recordFailure($store, $key, 'set');
                }
            }

            return $success;
        });
    }

    private function validateCacheOperation(string $key): void
    {
        if (!$this->validator->validateCacheKey($key)) {
            throw new InvalidCacheKeyException("Invalid cache key: {$key}");
        }

        if ($this->isBlacklisted($key)) {
            throw new CacheKeyBlacklistedException("Cache key is blacklisted: {$key}");
        }
    }

    private function getStores(string $key): array
    {
        return array_filter($this->stores, function($store) use ($key) {
            return $store->accepts($key);
        });
    }

    private function recordHit(CacheStore $store, string $key): void
    {
        $this->metrics->increment('cache.hits', [
            'store' => $store->getName(),
            'key_pattern' => $this->getKeyPattern($key)
        ]);
    }

    private function recordMiss(string $key): void
    {
        $this->metrics->increment('cache.misses', [
            'key_pattern' => $this->getKeyPattern($key)
        ]);
    }

    private function recordFailure(CacheStore $store, string $key, string $operation): void
    {
        $this->metrics->increment('cache.failures', [
            'store' => $store->getName(),
            'key_pattern' => $this->getKeyPattern($key),
            'operation' => $operation
        ]);

        Log::error('Cache operation failed', [
            'store' => $store->getName(),
            'key' => $key,
            'operation' => $operation,
            'timestamp' => now()
        ]);
    }

    private function getKeyPattern(string $key): string
    {
        return preg_replace('/[0-9]+/', '*', $key);
    }
}

// app/Core/Cache/CacheStore.php
abstract class CacheStore implements CacheStoreInterface 
{
    protected array $config;
    protected MetricsCollector $metrics;

    abstract public function get(string $key);
    abstract public function set(string $key, $value, $ttl = null): bool;
    abstract public function delete(string $key): bool;
    abstract public function flush(): bool;
    abstract public function getName(): string;

    public function accepts(string $key): bool
    {
        $pattern = $this->config['key_pattern'] ?? '*';
        return fnmatch($pattern, $key);
    }

    protected function validateTTL($ttl): int
    {
        if (is_null($ttl)) {
            return $this->config['default_ttl'] ?? 3600;
        }

        if (!is_numeric($ttl) || $ttl < 0) {
            throw new InvalidArgumentException('TTL must be non-negative');
        }

        return (int)$ttl;
    }

    protected function recordMetrics(string $operation, string $key, ?float $startTime = null): void
    {
        if ($startTime) {
            $this->metrics->timing("cache.{$operation}_time", microtime(true) - $startTime, [
                'store' => $this->getName()
            ]);
        }

        $this->metrics->increment("cache.{$operation}", [
            'store' => $this->getName(),
            'key_pattern' => $this->getKeyPattern($key)
        ]);
    }
}

// app/Core/Cache/RedisStore.php
class RedisStore extends CacheStore
{
    private Redis $redis;

    public function get(string $key)
    {
        $startTime = microtime(true);
        
        try {
            $value = $this->redis->get($key);
            $this->recordMetrics('get', $key, $startTime);
            return $value ? unserialize($value) : null;
        } catch (\Exception $e) {
            $this->recordFailure('get', $key, $e);
            throw new CacheException('Redis get operation failed', 0, $e);
        }
    }

    public function set(string $key, $value, $ttl = null): bool
    {
        $startTime = microtime(true);
        
        try {
            $ttl = $this->validateTTL($ttl);
            $success = $this->redis->setex($key, $ttl, serialize($value));
            $this->recordMetrics('set', $key, $startTime);
            return $success;
        } catch (\Exception $e) {
            $this->recordFailure('set', $key, $e);
            throw new CacheException('Redis set operation failed', 0, $e);
        }
    }

    public function getName(): string
    {
        return 'redis';
    }
}