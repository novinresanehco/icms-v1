<?php

namespace App\Core\Cache;

use App\Core\Security\CoreSecurityService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class CacheManager implements CacheInterface
{
    private CoreSecurityService $security;
    private array $stores;
    private array $config;
    private MetricsCollector $metrics;

    public function __construct(
        CoreSecurityService $security,
        MetricsCollector $metrics,
        array $config = []
    ) {
        $this->security = $security;
        $this->metrics = $metrics;
        $this->config = $config;
        $this->initializeStores();
    }

    public function get(string $key, Context $context): mixed
    {
        return $this->security->executeProtectedOperation(
            fn() => $this->executeGet($key),
            ['action' => 'cache.get', 'key' => $key, 'context' => $context]
        );
    }

    public function put(string $key, mixed $value, $ttl = null, Context $context): void
    {
        $this->security->executeProtectedOperation(
            fn() => $this->executePut($key, $value, $ttl),
            ['action' => 'cache.put', 'key' => $key, 'context' => $context]
        );
    }

    public function remember(string $key, callable $callback, $ttl = null, Context $context): mixed
    {
        return $this->security->executeProtectedOperation(
            fn() => $this->executeRemember($key, $callback, $ttl),
            ['action' => 'cache.remember', 'key' => $key, 'context' => $context]
        );
    }

    public function forget(string $key, Context $context): void
    {
        $this->security->executeProtectedOperation(
            fn() => $this->executeForget($key),
            ['action' => 'cache.forget', 'key' => $key, 'context' => $context]
        );
    }

    public function tags(array $tags): TaggedCache
    {
        return new TaggedCache($this, $tags);
    }

    private function executeGet(string $key): mixed
    {
        $startTime = microtime(true);
        
        try {
            foreach ($this->stores as $store) {
                if ($value = $store->get($this->normalizeKey($key))) {
                    $this->promoteToFasterStore($key, $value);
                    $this->recordHit($store, microtime(true) - $startTime);
                    return $this->decodeValue($value);
                }
            }
            
            $this->recordMiss(microtime(true) - $startTime);
            return null;
            
        } catch (\Exception $e) {
            $this->handleError($e, 'get', $key);
            return null;
        }
    }

    private function executePut(string $key, mixed $value, $ttl = null): void
    {
        $normalizedKey = $this->normalizeKey($key);
        $encodedValue = $this->encodeValue($value);
        $ttl = $ttl ?? $this->config['default_ttl'];

        foreach ($this->stores as $store) {
            try {
                $store->put($normalizedKey, $encodedValue, $ttl);
            } catch (\Exception $e) {
                $this->handleError($e, 'put', $key);
                continue;
            }
        }
    }

    private function executeRemember(string $key, callable $callback, $ttl = null): mixed
    {
        if ($value = $this->executeGet($key)) {
            return $value;
        }

        $value = $callback();
        $this->executePut($key, $value, $ttl);
        
        return $value;
    }

    private function executeForget(string $key): void
    {
        $normalizedKey = $this->normalizeKey($key);
        
        foreach ($this->stores as $store) {
            try {
                $store->forget($normalizedKey);
            } catch (\Exception $e) {
                $this->handleError($e, 'forget', $key);
                continue;
            }
        }
    }

    private function promoteToFasterStore(string $key, mixed $value): void
    {
        $stores = array_reverse($this->stores);
        $found = false;
        
        foreach ($stores as $store) {
            if ($found) {
                try {
                    $store->put(
                        $this->normalizeKey($key),
                        $value,
                        $this->config['promotion_ttl']
                    );
                } catch (\Exception $e) {
                    $this->handleError($e, 'promote', $key);
                    continue;
                }
            }
            if ($store->has($this->normalizeKey($key))) {
                $found = true;
            }
        }
    }

    private function initializeStores(): void
    {
        $this->stores = [
            new MemoryStore(),
            new RedisStore(Redis::connection()),
            new FileStore(storage_path('cache'))
        ];
    }

    private function normalizeKey(string $key): string
    {
        return hash('sha256', $key);
    }

    private function encodeValue(mixed $value): string
    {
        return base64_encode(serialize($value));
    }

    private function decodeValue(string $value): mixed
    {
        return unserialize(base64_decode($value));
    }

    private function recordHit(CacheStore $store, float $duration): void
    {
        $this->metrics->increment('cache.hits');
        $this->metrics->timing('cache.hit_time', $duration);
        $this->metrics->increment('cache.hits_by_store.' . get_class($store));
    }

    private function recordMiss(float $duration): void
    {
        $this->metrics->increment('cache.misses');
        $this->metrics->timing('cache.miss_time', $duration);
    }

    private function handleError(\Exception $e, string $operation, string $key): void
    {
        $this->metrics->increment('cache.errors');
        $this->metrics->increment('cache.errors_by_operation.' . $operation);
        
        // Log but don't throw to maintain cache transparency
        logger()->error('Cache operation failed', [
            'operation' => $operation,
            'key' => $key,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

abstract class CacheStore
{
    abstract public function get(string $key): mixed;
    abstract public function put(string $key, mixed $value, $ttl = null): void;
    abstract public function forget(string $key): void;
    abstract public function has(string $key): bool;
}

class MemoryStore extends CacheStore
{
    private array $storage = [];
    private array $expiration = [];

    public function get(string $key): mixed
    {
        if (!$this->has($key)) {
            return null;
        }
        return $this->storage[$key];
    }

    public function put(string $key, mixed $value, $ttl = null): void
    {
        $this->storage[$key] = $value;
        if ($ttl) {
            $this->expiration[$key] = time() + $ttl;
        }
    }

    public function forget(string $key): void
    {
        unset($this->storage[$key], $this->expiration[$key]);
    }

    public function has(string $key): bool
    {
        if (!isset($this->storage[$key])) {
            return false;
        }
        
        if (isset($this->expiration[$key]) && time() > $this->expiration[$key]) {
            $this->forget($key);
            return false;
        }
        
        return true;
    }
}

class RedisStore extends CacheStore
{
    private \Redis $redis;

    public function __construct(\Redis $redis)
    {
        $this->redis = $redis;
    }

    public function get(string $key): mixed
    {
        return $this->redis->get($key);
    }

    public function put(string $key, mixed $value, $ttl = null): void
    {
        if ($ttl) {
            $this->redis->setex($key, $ttl, $value);
        } else {
            $this->redis->set($key, $value);
        }
    }

    public function forget(string $key): void
    {
        $this->redis->del($key);
    }

    public function has(string $key): bool
    {
        return $this->redis->exists($key);
    }
}

class FileStore extends CacheStore
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function get(string $key): mixed
    {
        if (!$this->has($key)) {
            return null;
        }
        
        return file_get_contents($this->getPath($key));
    }

    public function put(string $key, mixed $value, $ttl = null): void
    {
        $path = $this->getPath($key);
        $directory = dirname($path);
        
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        
        file_put_contents($path, $value);
        
        if ($ttl) {
            touch($path, time() + $ttl);
        }
    }

    public function forget(string $key): void
    {
        @unlink($this->getPath($key));
    }

    public function has(string $key): bool
    {
        $path = $this->getPath($key);
        
        if (!file_exists($path)) {
            return false;
        }
        
        if (filemtime($path) < time()) {
            $this->forget($key);
            return false;
        }
        
        return true;
    }

    private function getPath(string $key): string
    {
        return $this->path . '/' . substr($key, 0, 2) . '/' . $key;
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

    public function get(string $key, Context $context): mixed
    {
        return $this->cache->get($this->taggedKey($key), $context);
    }

    public function put(string $key, mixed $value, $ttl = null, Context $context): void
    {
        $this->cache->put($this->taggedKey($key), $value, $ttl, $context);
        $this->updateTaggedKeys($key);
    }

    public function forget(string $key, Context $context): void
    {
        $this->cache->forget($this->taggedKey($key), $context);
        $this->removeFromTaggedKeys($key);
    }

    public function flush(Context $context): void
    {
        foreach ($this->tags as $tag) {
            $keys = $this->getTaggedKeys($tag);
            foreach ($keys as $key) {
                $this->cache->forget($key, $context);
            }
            $this->cache->forget("tag:$tag", $context);
        }
    }

    private function taggedKey(string $key): string
    {
        return implode(':', array_merge($this->tags, [$key]));
    }

    private function updateTaggedKeys(string $key): void
    {
        foreach ($this->tags as $tag) {
            $keys = $this->getTaggedKeys($tag);
            $keys[] = $key;
            $this->cache->put("tag:$tag", array_unique($keys), null, new Context());
        }
    }

    private function removeFromTaggedKeys(string $key): void
    {
        foreach ($this->tags as $tag) {
            $keys = $this->getTaggedKeys($tag);
            $keys = array_diff($keys, [$key]);
            $this->cache->put("tag:$tag", $keys, null, new Context());
        }
    }

    private function getTaggedKeys(string $tag): array
    {
        return $this->cache->get("tag:$tag", new Context()) ?? [];
    }
}
