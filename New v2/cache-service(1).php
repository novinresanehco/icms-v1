<?php

namespace App\Core\Cache;

class CacheService implements CacheInterface
{
    private CacheStore $store;
    private SecurityService $security;
    private MonitoringService $monitor;
    private int $defaultTtl;

    public function __construct(
        CacheStore $store,
        SecurityService $security,
        MonitoringService $monitor,
        int $defaultTtl = 3600
    ) {
        $this->store = $store;
        $this->security = $security;
        $this->monitor = $monitor;
        $this->defaultTtl = $defaultTtl;
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $cacheKey = $this->generateCacheKey($key);
        
        try {
            if ($cached = $this->get($cacheKey)) {
                $this->monitor->trackCacheHit($key);
                return $cached;
            }

            $value = $callback();
            $this->set($cacheKey, $value, $ttl);
            
            $this->monitor->trackCacheMiss($key);
            return $value;

        } catch (\Exception $e) {
            $this->monitor->trackCacheFailure($key, $e);
            throw $e;
        }
    }

    public function get(string $key): mixed
    {
        $encrypted = $this->store->get($key);
        
        if (!$encrypted) {
            return null;
        }

        return $this->security->decryptData($encrypted);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        $encrypted = $this->security->encryptData($value);
        $this->store->put($key, $encrypted, $ttl ?? $this->defaultTtl);
    }

    public function forget(string $key): void
    {
        $this->store->forget($this->generateCacheKey($key));
    }

    public function tags(array $tags): static
    {
        $this->store->tags($tags);
        return $this;
    }

    protected function generateCacheKey(string $key): string
    {
        return hash_hmac(
            'sha256',
            $key,
            config('app.key')
        );
    }

    public function flush(): void
    {
        $this->store->flush();
    }

    public function increment(string $key, int $value = 1): int
    {
        return $this->store->increment($key, $value);
    }

    public function decrement(string $key, int $value = 1): int
    {
        return $this->store->decrement($key, $value);
    }

    public function forever(string $key, mixed $value): void
    {
        $this->set($key, $value, null);
    }
}

interface CacheInterface
{
    public function remember(string $key, callable $callback, ?int $ttl = null): mixed;
    public function get(string $key): mixed;
    public function set(string $key, mixed $value, ?int $ttl = null): void;
    public function forget(string $key): void;
    public function tags(array $tags): static;
    public function flush(): void;
}

class CacheException extends \Exception
{
    private array $context;

    public function __construct(string $message, array $context = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->context = $context;
    }

    public function getContext(): array
    {
        return $this->context;
    }
}