<?php

namespace App\Core\Cache;

class CacheManager
{
    private array $stores;
    private CacheMetrics $metrics;
    private LockManager $lockManager;
    private array $config;

    public function __construct(array $stores, CacheMetrics $metrics, LockManager $lockManager, array $config)
    {
        $this->stores = $stores;
        $this->metrics = $metrics;
        $this->lockManager = $lockManager;
        $this->config = $config;
    }

    public function get(string $key, array $tags = []): mixed
    {
        $startTime = microtime(true);
        $value = null;

        foreach ($this->stores as $store) {
            if ($store->supportsTags($tags) && $value = $store->get($key)) {
                $this->metrics->recordHit($store->getName(), microtime(true) - $startTime);
                return $value;
            }
        }

        $this->metrics->recordMiss(microtime(true) - $startTime);
        return null;
    }

    public function set(string $key, mixed $value, array $tags = [], ?int $ttl = null): bool
    {
        $lock = $this->lockManager->acquire($key);

        try {
            $success = true;
            
            foreach ($this->stores as $store) {
                if ($store->supportsTags($tags)) {
                    $success = $store->set($key, $value, $tags, $ttl) && $success;
                }
            }
            
            return $success;
        } finally {
            $lock->release();
        }
    }

    public function delete(string $key): bool
    {
        $lock = $this->lockManager->acquire($key);

        try {
            $success = true;
            
            foreach ($this->stores as $store) {
                $success = $store->delete($key) && $success;
            }
            
            return $success;
        } finally {
            $lock->release();
        }
    }

    public function clear(): bool
    {
        $success = true;
        
        foreach ($this->stores as $store) {
            $success = $store->clear() && $success;
        }
        
        return $success;
    }

    public function invalidateTags(array $tags): bool
    {
        $success = true;
        
        foreach ($this->stores as $store) {
            if ($store->supportsTags($tags)) {
                $success = $store->invalidateTags($tags) && $success;
            }
        }
        
        return $success;
    }

    public function remember(string $key, \Closure $callback, array $tags = [], ?int $ttl = null): mixed
    {
        $value = $this->get($key, $tags);

        if ($value !== null) {
            return $value;
        }

        $lock = $this->lockManager->acquire($key);

        try {
            $value = $callback();
            $this->set($key, $value, $tags, $ttl);
            return $value;
        } finally {
            $lock->release();
        }
    }

    public function tags(array $tags): TaggedCache
    {
        return new TaggedCache($this, $tags);
    }

    public function getMetrics(): array
    {
        return $this->metrics->getMetrics();
    }
}

class TaggedCache
{
    private CacheManager $manager;
    private array $tags;

    public function __construct(CacheManager $manager, array $tags)
    {
        $this->manager = $manager;
        $this->tags = $tags;
    }

    public function get(string $key): mixed
    {
        return $this->manager->get($key, $this->tags);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->manager->set($key, $value, $this->tags, $ttl);
    }

    public function remember(string $key, \Closure $callback, ?int $ttl = null): mixed
    {
        return $this->manager->remember($key, $callback, $this->tags, $ttl);
    }

    public function flush(): bool
    {
        return $this->manager->invalidateTags($this->tags);
    }
}

class CacheStore
{
    private string $name;
    private CacheClient $client;
    private Serializer $serializer;
    private array $config;

    public function supportsTags(array $tags): bool
    {
        return empty($tags) || $this->config['supports_tags'] ?? false;
    }

    public function get(string $key): mixed
    {
        $value = $this->client->get($this->getNamespacedKey($key));
        
        if ($value === null) {
            return null;
        }
        
        return $this->serializer->unserialize($value);
    }

    public function set(string $key, mixed $value, array $tags = [], ?int $ttl = null): bool
    {
        $serialized = $this->serializer->serialize($value);
        
        $success = $this->client->set(
            $this->getNamespacedKey($key),
            $serialized,
            $ttl ?? $this->config['default_ttl']
        );
        
        if ($success && !empty($tags)) {
            $this->setTags($key, $tags);
        }
        
        return $success;
    }

    public function delete(string $key): bool
    {
        return $this->client->delete($this->getNamespacedKey($key));
    }

    public function clear(): bool
    {
        return $this->client->flush();
    }

    public function invalidateTags(array $tags): bool
    {
        $keys = $this->getKeysByTags($tags);
        
        foreach ($keys as $key) {
            $this->delete($key);
        }
        
        return true;
    }

    protected function getNamespacedKey(string $key): string
    {
        return "{$this->config['namespace']}:{$key}";
    }

    protected function setTags(string $key, array $tags): void
    {
        foreach ($tags as $tag) {
            $this->client->sAdd("tag:{$tag}", $key);
        }
    }

    protected function getKeysByTags(array $tags): array
    {
        $keys = [];
        
        foreach ($tags as $tag) {
            $keys = array_merge(
                $keys,
                $this->client->sMembers("tag:{$tag}")
            );
        }
        
        return array_unique($keys);
    }
}

class