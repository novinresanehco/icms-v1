<?php

namespace App\Core\Cache;

class EnhancedCacheManager implements CacheManagerInterface
{
    protected CacheStore $store;
    protected EncryptionService $encryption;
    protected MetricsCollector $metrics;
    protected array $config;

    protected array $locks = [];

    public function remember(array|string $key, callable $callback, ?int $ttl = null): mixed
    {
        $cacheKey = $this->resolveKey($key);
        
        if ($value = $this->get($cacheKey)) {
            $this->recordHit($cacheKey);
            return $value;
        }

        $this->recordMiss($cacheKey);
        return $this->rememberWithLock($cacheKey, $callback, $ttl);
    }

    public function rememberWithLock(string $key, callable $callback, ?int $ttl): mixed
    {
        $lock = $this->getLock($key);
        
        try {
            $lock->acquire();
            $value = $callback();
            $this->set($key, $value, $ttl);
            return $value;
        } finally {
            $lock->release();
        }
    }

    public function tags(array $tags): static
    {
        return new static($this->store->tags($tags));
    }

    protected function resolveKey(array|string $key): string
    {
        if (is_array($key)) {
            return implode(':', $key);
        }
        return $key;
    }

    protected function getLock(string $key): Lock
    {
        if (!isset($this->locks[$key])) {
            $this->locks[$key] = new Lock($key, $this->store);
        }
        return $this->locks[$key];
    }

    protected function recordHit(string $key): void
    {
        $this->metrics->increment('cache.hits', ['key' => $key]);
    }

    protected function recordMiss(string $key): void
    {
        $this->metrics->increment('cache.misses', ['key' => $key]);
    }
}

class Lock
{
    protected CacheStore $store;
    protected string $key;
    protected int $timeout;
    protected ?string $owner = null;

    public function acquire(): bool
    {
        $this->owner = Str::random();
        
        return $this->store->set(
            $this->getLockKey(),
            $this->owner,
            $this->timeout
        );
    }

    public function release(): bool
    {
        if ($this->isOwnedByCurrentProcess()) {
            return $this->store->delete($this->getLockKey());
        }
        return false;
    }

    protected function isOwnedByCurrentProcess(): bool
    {
        return $this->store->get($this->getLockKey()) === $this->owner;
    }

    protected function getLockKey(): string
    {
        return "lock:{$this->key}";
    }
}

class CacheMetrics
{
    public function __construct(
        protected MetricsCollector $metrics,
        protected string $prefix = 'cache'
    ) {}

    public function recordHit(string $key): void
    {
        $this->increment('hits', $key);
    }

    public function recordMiss(string $key): void
    {
        $this->increment('misses', $key);
    }

    public function recordSet(string $key): void
    {
        $this->increment('sets', $key);
    }

    protected function increment(string $type, string $key): void
    {
        $this->metrics->increment("{$this->prefix}.{$type}", [
            'key' => $key,
            'timestamp' => time()
        ]);
    }
}
