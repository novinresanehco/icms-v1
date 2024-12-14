<?php

namespace App\Core\Template\Cache;

class TemplateCacheStrategy
{
    private CacheManager $cache;
    private SecurityManager $security;
    private array $config;

    public function __construct(
        CacheManager $cache,
        SecurityManager $security,
        array $config
    ) {
        $this->cache = $cache;
        $this->security = $security;
        $this->config = $config;
    }

    public function get(string $key): mixed
    {
        return DB::transaction(function() use ($key) {
            $this->security->validateCacheKey($key);
            return $this->cache->get($this->generateKey($key));
        });
    }

    public function set(string $key, mixed $value, array $tags = []): void
    {
        DB::transaction(function() use ($key, $value, $tags) {
            $this->security->validateCacheOperation($key, $value);
            
            $cacheKey = $this->generateKey($key);
            $this->cache->tags($tags)->put(
                $cacheKey,
                $this->security->encryptValue($value),
                $this->getTTL()
            );
        });
    }

    public function invalidate(array $tags = []): void
    {
        $this->security->validateInvalidation($tags);
        $this->cache->tags($tags)->flush();
    }

    private function generateKey(string $key): string
    {
        return hash_hmac(
            'sha256',
            $key,
            $this->config['key_salt']
        );
    }

    private function getTTL(): int
    {
        return $this->config['cache_ttl'] ?? 3600;
    }
}

class TemplateCache
{
    private TemplateCacheStrategy $strategy;
    private array $locks = [];

    public function remember(string $key, callable $callback): mixed
    {
        $this->acquireLock($key);

        try {
            if ($cached = $this->strategy->get($key)) {
                return $cached;
            }

            $value = $callback();
            $this->strategy->set($key, $value);
            return $value;
        } finally {
            $this->releaseLock($key);
        }
    }

    private function acquireLock(string $key): void
    {
        if (isset($this->locks[$key])) {
            throw new CacheLockException("Cache key already locked: {$key}");
        }
        $this->locks[$key] = true;
    }

    private function releaseLock(string $key): void
    {
        unset($this->locks[$key]);
    }
}

class CacheLockException extends \Exception {}

interface CacheStrategyInterface
{
    public function get(string $key): mixed;
    public function set(string $key, mixed $value, array $tags = []): void;
    public function invalidate(array $tags = []): void;
}
