<?php

namespace App\Core\Cache;

use Illuminate\Support\Facades\{Cache, Log};
use App\Core\Interfaces\{CacheManagerInterface, MonitorInterface};
use App\Core\Exceptions\{CacheException, PerformanceException};

class CacheManager implements CacheManagerInterface
{
    private array $stores;
    private MonitorInterface $monitor;
    private ValidationService $validator;
    private array $config;
    private ?string $defaultStore;

    public function __construct(
        array $stores,
        MonitorInterface $monitor,
        ValidationService $validator,
        array $config
    ) {
        $this->stores = $stores;
        $this->monitor = $monitor;
        $this->validator = $validator;
        $this->config = $config;
        $this->defaultStore = $config['default_store'] ?? null;
    }

    public function remember(string $key, $ttl, callable $callback, array $options = []): mixed
    {
        $this->validateKey($key);
        $store = $this->getStore($options['store'] ?? null);
        $tags = $options['tags'] ?? [];

        try {
            $startTime = microtime(true);
            $cache = $store->tags($tags);

            if ($this->shouldBypassCache($options)) {
                return $this->executeCallback($callback, $key);
            }

            $value = $cache->get($key);

            if ($value !== null) {
                $this->recordCacheHit($key, microtime(true) - $startTime);
                return $this->unserializeValue($value);
            }

            $value = $this->executeCallback($callback, $key);
            $serialized = $this->serializeValue($value);

            $cache->put($key, $serialized, $this->normalizeTTL($ttl));
            $this->recordCacheMiss($key, microtime(true) - $startTime);

            return $value;

        } catch (\Exception $e) {
            $this->handleCacheFailure($e, $key);
            return $this->executeCallback($callback, $key);
        }
    }

    public function rememberForever(string $key, callable $callback, array $options = []): mixed
    {
        return $this->remember($key, null, $callback, $options);
    }

    public function forget(string $key, array $options = []): bool
    {
        try {
            $store = $this->getStore($options['store'] ?? null);
            $tags = $options['tags'] ?? [];

            return $store->tags($tags)->forget($key);

        } catch (\Exception $e) {
            $this->handleCacheFailure($e, $key);
            return false;
        }
    }

    public function flush(array $options = []): bool
    {
        try {
            $store = $this->getStore($options['store'] ?? null);
            $tags = $options['tags'] ?? [];

            if (!empty($tags)) {
                return $store->tags($tags)->flush();
            }

            return $store->flush();

        } catch (\Exception $e) {
            $this->handleCacheFailure($e, 'flush');
            return false;
        }
    }

    public function warmup(array $keys, callable $callback, array $options = []): void
    {
        foreach ($keys as $key) {
            if (!$this->has($key, $options)) {
                $this->remember($key, $this->config['default_ttl'], $callback, $options);
            }
        }
    }

    protected function getStore(?string $name = null): mixed
    {
        $storeName = $name ?? $this->defaultStore;

        if (!isset($this->stores[$storeName])) {
            throw new CacheException("Cache store not found: {$storeName}");
        }

        return $this->stores[$storeName];
    }

    protected function validateKey(string $key): void
    {
        if (!$this->validator->validateCacheKey($key)) {
            throw new CacheException('Invalid cache key format');
        }

        if (strlen($key) > $this->config['max_key_length']) {
            throw new CacheException('Cache key too long');
        }
    }

    protected function shouldBypassCache(array $options): bool
    {
        return $options['bypass'] ?? false;
    }

    protected function executeCallback(callable $callback, string $key): mixed
    {
        try {
            $startTime = microtime(true);
            $result = $callback();
            $this->recordCallbackExecution($key, microtime(true) - $startTime);

            return $result;

        } catch (\Exception $e) {
            $this->recordCallbackFailure($key, $e);
            throw $e;
        }
    }

    protected function serializeValue($value): string
    {
        try {
            return serialize($value);
        } catch (\Exception $e) {
            throw new CacheException('Value serialization failed', 0, $e);
        }
    }

    protected function unserializeValue(string $value): mixed
    {
        try {
            return unserialize($value);
        } catch (\Exception $e) {
            throw new CacheException('Value unserialization failed', 0, $e);
        }
    }

    protected function normalizeTTL($ttl): ?int
    {
        if ($ttl === null) {
            return null;
        }

        if ($ttl instanceof \DateTime) {
            return max(0, $ttl->getTimestamp() - time());
        }

        return max(0, (int) $ttl);
    }

    protected function handleCacheFailure(\Exception $e, string $key): void
    {
        $this->monitor->recordFailure('cache', [
            'key' => $key,
            'exception' => $e->getMessage(),
            'store' => $this->defaultStore
        ]);

        Log::error('Cache operation failed', [
            'key' => $key,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function recordCacheHit(string $key, float $duration): void
    {
        $this->monitor->recordMetric('cache_hit', [
            'key' => $key,
            'duration' => $duration,
            'store' => $this->defaultStore
        ]);
    }

    protected function recordCacheMiss(string $key, float $duration): void
    {
        $this->monitor->recordMetric('cache_miss', [
            'key' => $key,
            'duration' => $duration,
            'store' => $this->defaultStore
        ]);
    }

    protected function recordCallbackExecution(string $key, float $duration): void
    {
        $this->monitor->recordMetric('cache_callback', [
            'key' => $key,
            'duration' => $duration
        ]);

        if ($duration > $this->config['callback_warning_threshold']) {
            Log::warning('Slow cache callback execution', [
                'key' => $key,
                'duration' => $duration
            ]);
        }
    }

    protected function recordCallbackFailure(string $key, \Exception $e): void
    {
        $this->monitor->recordFailure('cache_callback', [
            'key' => $key,
            'exception' => $e->getMessage()
        ]);
    }
}
