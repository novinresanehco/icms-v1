<?php

namespace App\Core\Cache;

class CacheManager
{
    private array $drivers = [];
    private array $tags = [];
    private array $locks = [];
    private array $metrics = [];

    public function store(string $key, $value, ?int $ttl = null, array $tags = []): bool
    {
        $lock = $this->acquireLock($key);

        try {
            $success = $this->driver()->set(
                $this->normalizeKey($key),
                $this->serialize($value),
                $ttl
            );

            if ($success && !empty($tags)) {
                $this->tagItem($key, $tags);
            }

            return $success;
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function retrieve(string $key, $default = null)
    {
        $value = $this->driver()->get($this->normalizeKey($key));
        return $value !== null ? $this->unserialize($value) : $default;
    }

    public function remember(string $key, \Closure $callback, ?int $ttl = null, array $tags = [])
    {
        $value = $this->retrieve($key);

        if ($value !== null) {
            $this->recordHit($key);
            return $value;
        }

        $value = $callback();
        $this->store($key, $value, $ttl, $tags);
        $this->recordMiss($key);

        return $value;
    }

    public function tags(array $tags): self
    {
        $this->tags = array_unique(array_merge($this->tags, $tags));
        return $this;
    }

    public function invalidate(array|string $tags): bool
    {
        $tags = (array) $tags;
        $keys = $this->getTaggedKeys($tags);

        foreach ($keys as $key) {
            $this->driver()->delete($key);
        }

        $this->removeTagsFromIndex($tags);
        return true;
    }

    public function flush(): bool
    {
        return $this->driver()->flush();
    }

    private function tagItem(string $key, array $tags): void
    {
        foreach ($tags as $tag) {
            $this->driver()->sAdd("tag:{$tag}", $key);
        }
    }

    private function getTaggedKeys(array $tags): array
    {
        $keys = [];
        foreach ($tags as $tag) {
            $keys = array_merge(
                $keys,
                $this->driver()->sMembers("tag:{$tag}") ?: []
            );
        }
        return array_unique($keys);
    }

    private function removeTagsFromIndex(array $tags): void
    {
        foreach ($tags as $tag) {
            $this->driver()->del("tag:{$tag}");
        }
    }

    private function acquireLock(string $key): Lock
    {
        $lock = new Lock($key, $this->driver());
        $this->locks[$key] = $lock;
        return $lock;
    }

    private function releaseLock(Lock $lock): void
    {
        $lock->release();
        unset($this->locks[$lock->getKey()]);
    }

    private function recordHit(string $key): void
    {
        $this->metrics[$key]['hits'] = ($this->metrics[$key]['hits'] ?? 0) + 1;
    }

    private function recordMiss(string $key): void
    {
        $this->metrics[$key]['misses'] = ($this->metrics[$key]['misses'] ?? 0) + 1;
    }

    private function serialize($value): string
    {
        return serialize($value);
    }

    private function unserialize(string $value)
    {
        return unserialize($value);
    }

    private function normalizeKey(string $key): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $key);
    }

    private function driver()
    {
        return $this->drivers['default'] ?? $this->resolveDefaultDriver();
    }
}

class Lock
{
    private string $key;
    private $driver;
    private string $token;

    public function __construct(string $key, $driver)
    {
        $this->key = "lock:{$key}";
        $this->driver = $driver;
        $this->token = uniqid('', true);
        $this->acquire();
    }

    private function acquire(): bool
    {
        $attempts = 0;
        $maxAttempts = 100;

        while (!$this->driver->setnx($this->key, $this->token)) {
            if (++$attempts === $maxAttempts) {
                throw new \RuntimeException("Could not acquire lock for key: {$this->key}");
            }
            usleep(100000); // 100ms
        }

        $this->driver->expire($this->key, 60); // 60 seconds
        return true;
    }

    public function release(): bool
    {
        if ($this->driver->get($this->key) === $this->token) {
            return $this->driver->del($this->key);
        }
        return false;
    }

    public function getKey(): string
    {
        return $this->key;
    }
}

class CacheMetrics
{
    private array $data = [];

    public function record(string $key, string $operation): void
    {
        $this->data[$key][$operation] = ($this->data[$key][$operation] ?? 0) + 1;
        $this->data[$key]['last_accessed'] = microtime(true);
    }

    public function getHitRatio(string $key): float
    {
        $hits = $this->data[$key]['hits'] ?? 0;
        $total = $hits + ($this->data[$key]['misses'] ?? 0);
        return $total > 0 ? $hits / $total : 0;
    }

    public function getAccessFrequency(string $key): float
    {
        $accesses = ($this->data[$key]['hits'] ?? 0) + ($this->data[$key]['misses'] ?? 0);
        $timespan = microtime(true) - ($this->data[$key]['first_accessed'] ?? microtime(true));
        return $timespan > 0 ? $accesses / $timespan : 0;
    }

    public function reset(): void
    {
        $this->data = [];
    }

    public function getMetrics(): array
    {
        return $this->data;
    }
}

class CacheStrategy
{
    private int $ttl;
    private array $tags;
    private bool $useCompression;
    private string $serializationFormat;

    public function __construct(array $config = [])
    {
        $this->ttl = $config['ttl'] ?? 3600;
        $this->tags = $config['tags'] ?? [];
        $this->useCompression = $config['compression'] ?? false;
        $this->serializationFormat = $config['serialization'] ?? 'php';
    }

    public function getTtl(): int
    {
        return $this->ttl;
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function shouldCompress(): bool
    {
        return $this->useCompression;
    }

    public function getSerializationFormat(): string
    {
        return $this->serializationFormat;
    }
}
