<?php

namespace App\Services;

use App\Interfaces\SecurityServiceInterface;
use Illuminate\Support\Facades\{Cache, Log};
use Illuminate\Contracts\Cache\Repository;
use App\Exceptions\CacheException;

class CacheService
{
    private SecurityServiceInterface $security;
    private Repository $store;
    private array $securityTags = ['content', 'user', 'media'];
    
    public function __construct(
        SecurityServiceInterface $security,
        Repository $store
    ) {
        $this->security = $security;
        $this->store = $store;
    }

    public function remember(string $key, $data, int $ttl = 3600): mixed
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeRemember($key, $data, $ttl),
            ['action' => 'cache.write', 'key' => $key]
        );
    }

    private function executeRemember(string $key, $data, int $ttl): mixed
    {
        $this->validateCacheKey($key);
        
        try {
            return $this->store->remember(
                $this->normalizeKey($key),
                $ttl,
                function() use ($data) {
                    return is_callable($data) ? $data() : $data;
                }
            );
        } catch (\Throwable $e) {
            Log::error('Cache operation failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            throw new CacheException('Cache operation failed', 0, $e);
        }
    }

    public function rememberForever(string $key, $data): mixed
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeRememberForever($key, $data),
            ['action' => 'cache.write', 'key' => $key]
        );
    }

    private function executeRememberForever(string $key, $data): mixed
    {
        $this->validateCacheKey($key);
        
        try {
            return $this->store->rememberForever(
                $this->normalizeKey($key),
                function() use ($data) {
                    return is_callable($data) ? $data() : $data;
                }
            );
        } catch (\Throwable $e) {
            Log::error('Cache operation failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            throw new CacheException('Cache operation failed', 0, $e);
        }
    }

    public function tags(array $tags): self
    {
        $this->validateTags($tags);
        $this->store = $this->store->tags($tags);
        return $this;
    }

    public function invalidate(string $key): bool
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeInvalidate($key),
            ['action' => 'cache.invalidate', 'key' => $key]
        );
    }

    private function executeInvalidate(string $key): bool
    {
        $this->validateCacheKey($key);
        
        try {
            return $this->store->forget($this->normalizeKey($key));
        } catch (\Throwable $e) {
            Log::error('Cache invalidation failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            throw new CacheException('Cache invalidation failed', 0, $e);
        }
    }

    public function invalidateTag(string $tag): bool
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeInvalidateTag($tag),
            ['action' => 'cache.invalidate.tag', 'tag' => $tag]
        );
    }

    private function executeInvalidateTag(string $tag): bool
    {
        $this->validateTags([$tag]);
        
        try {
            return $this->store->tags($tag)->flush();
        } catch (\Throwable $e) {
            Log::error('Cache tag invalidation failed', [
                'tag' => $tag,
                'error' => $e->getMessage()
            ]);
            throw new CacheException('Cache tag invalidation failed', 0, $e);
        }
    }

    public function get(string $key, $default = null): mixed
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeGet($key, $default),
            ['action' => 'cache.read', 'key' => $key]
        );
    }

    private function executeGet(string $key, $default): mixed
    {
        $this->validateCacheKey($key);
        
        try {
            return $this->store->get(
                $this->normalizeKey($key),
                $default instanceof \Closure ? $default : fn() => $default
            );
        } catch (\Throwable $e) {
            Log::error('Cache retrieval failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            throw new CacheException('Cache retrieval failed', 0, $e);
        }
    }

    public function has(string $key): bool
    {
        return $this->security->validateSecureOperation(
            fn() => $this->executeHas($key),
            ['action' => 'cache.check', 'key' => $key]
        );
    }

    private function executeHas(string $key): bool
    {
        $this->validateCacheKey($key);
        
        try {
            return $this->store->has($this->normalizeKey($key));
        } catch (\Throwable $e) {
            Log::error('Cache check failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            throw new CacheException('Cache check failed', 0, $e);
        }
    }

    private function validateCacheKey(string $key): void
    {
        if (empty($key)) {
            throw new CacheException('Cache key cannot be empty');
        }

        if (strlen($key) > 250) {
            throw new CacheException('Cache key too long');
        }

        if (!preg_match('/^[\w\-\.]+$/', $key)) {
            throw new CacheException('Invalid cache key format');
        }
    }

    private function validateTags(array $tags): void
    {
        foreach ($tags as $tag) {
            if (!in_array($tag, $this->securityTags)) {
                throw new CacheException('Invalid cache tag: ' . $tag);
            }
        }
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(trim($key));
    }
}
