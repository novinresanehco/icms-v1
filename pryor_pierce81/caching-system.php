<?php

namespace App\Core\Cache;

class CacheManager
{
    private array $stores = [];
    private array $tags = [];
    private MetricsCollector $metrics;

    public function store(string $store = 'default'): CacheStore
    {
        if (!isset($this->stores[$store])) {
            throw new CacheException("Cache store not configured: $store");
        }
        
        return $this->stores[$store];
    }

    public function tags(array $tags): TaggedCache
    {
        return new TaggedCache($this->store(), $tags);
    }

    public function add(string $key, $value, ?int $ttl = null): bool
    {
        if ($this->has($key)) {
            return false;
        }

        return $this->put($key, $value, $ttl);
    }

    public function put(string $key, $value, ?int $ttl = null): bool
    {
        return $this->store()->put($key, $value, $ttl);
    }

    public function get(string $key, $default = null)
    {
        $value = $this->store()->get($key);
        $this->metrics->recordAccess($key, $value !== null);
        return $value ?? $default;
    }

    public function remember(string $key, \Closure $callback, ?int $ttl = null)
    {
        if ($value = $this->get($key)) {
            return $value;
        }

        $value = $callback();
        $this->put($key, $value, $ttl);
        return $value;
    }

    public function forget(string $key): bool
    {
        return $this->store()->forget($key);
    }

    public function flush(): bool
    {
        return $this->store()->flush();
    }
}

class CacheStore
{
    protected Driver $driver;
    protected Serializer $serializer;
    protected array $options;

    public function put(string $key, $value, ?int $ttl = null): bool
    {
        $serialized = $this->serializer->serialize($value);
        return $this->driver->set($this->prefix($key), $serialized, $ttl);
    }

    public function get(string $key)
    {
        $value = $this->driver->get($this->prefix($key));
        return $value ? $this->serializer->unserialize($value) : null;
    }

    public function increment(string $key, int $value = 1): int
    {
        return $this->driver->increment($this->prefix($key), $value);
    }

    public function decrement(string $key, int $value = 1): int
    {
        return $this->driver->decrement($this->prefix($key), $value);
    }

    public function forget(string $key): bool
    {
        return $this->driver->delete($this->prefix($key));
    }

    public function flush(): bool
    {
        return $this->driver->flush();
    }

    protected function prefix(string $key): string
    {
        return $this->options['prefix'] . $key;
    }
}

class TaggedCache extends CacheStore
{
    private array $tags;

    public function __construct(CacheStore $store, array $tags)
    {
        $this->tags = $tags;
        parent::__construct($store->driver, $store->serializer, $store->options);
    }

    public function put(string $key, $value, ?int $ttl = null): bool
    {
        foreach ($this->tags as $tag) {
            $this->driver->sAdd($this->tagKey($tag), $key);
        }

        return parent::put($key, $value, $ttl);
    }

    public function flush(): bool
    {
        $keys = [];
        foreach ($this->tags as $tag) {
            $keys = array_merge($keys, $this->driver->sMembers($this->tagKey($tag)));
            $this->driver->delete($this->tagKey($tag));
        }

        foreach ($keys as $key) {
            $this->forget($key);
        }

        return true;
    }

    private function tagKey(string $tag): string
    {
        return "tag:{$tag}:keys";
    }
}

class RedisDriver implements Driver
{
    private $redis;

    public function get(string $key)
    {
        return $this->redis->get($key);
    }

    public function set(string $key, string $value, ?int $ttl = null): bool
    {
        if ($ttl === null) {
            return $this->redis->set($key, $value);
        }

        return $this->redis->setex($key, $ttl, $value);
    }

    public function delete(string $key): bool
    {
        return $this->redis->del($key) > 0;
    }

    public function increment(string $key, int $value = 1): int
    {
        return $this->redis->incrby($key, $value);
    }

    public function decrement(string $key, int $value = 1): int
    {
        return $this->redis->decrby($key, $value);
    }

    public function flush(): bool
    {
        return $this->redis->flushdb();
    }

    public function sAdd(string $key, string $member): bool
    {
        return $this->redis->sAdd($key, $member) > 0;
    }

    public function sMembers(string $key): array
    {
        return $this->redis->sMembers($key);
    }
}

class MemcachedDriver implements Driver
{
    private $memcached;

    public function get(string $key)
    {
        return $this->memcached->get($key);
    }

    public function set(string $key, string $value, ?int $ttl = null): bool
    {
        return $this->memcached->set($key, $value, $ttl ?? 0);
    }

    public function delete(string $key): bool
    {
        return $this->memcached->delete($key);
    }

    public function increment(string $key, int $value = 1): int
    {
        return $this->memcached->increment($key, $value);
    }

    public function decrement(string $key, int $value = 1): int
    {
        return $this->memcached->decrement($key, $value);
    }

    public function flush(): bool
    {
        return $this->memcached->flush();
    }
}

interface Driver
{
    public function get(string $key);
    public function set(string $key, string $value, ?int $ttl = null): bool;
    public function delete(string $key): bool;
    public function increment(string $key, int $value = 1): int;
    public function decrement(string $key, int $value = 1): int;
    public function flush(): bool;
}

class CacheException extends \Exception {}
