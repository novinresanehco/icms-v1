<?php

namespace App\Core\Cache;

use Illuminate\Support\Facades\Cache;
use App\Core\Security\SecurityContext;
use App\Core\Contracts\CacheableEntity;

class CacheManager implements CacheInterface
{
    private SecurityManager $security;
    private array $config;
    
    public function __construct(SecurityManager $security, array $config)
    {
        $this->security = $security;
        $this->config = $config;
    }

    public function remember(string $key, $ttl, callable $callback, ?SecurityContext $context = null)
    {
        $cacheKey = $this->generateSecureKey($key, $context);
        
        if ($this->has($cacheKey)) {
            $value = Cache::get($cacheKey);
            if ($this->validateCachedData($value, $context)) {
                return $value;
            }
        }

        $value = $callback();
        
        if ($value instanceof CacheableEntity) {
            $this->setCacheableEntity($cacheKey, $value, $ttl);
        } else {
            Cache::put($cacheKey, $value, $this->calculateTTL($ttl));
        }

        return $value;
    }

    public function invalidate(string $key, ?SecurityContext $context = null): void
    {
        $cacheKey = $this->generateSecureKey($key, $context);
        Cache::forget($cacheKey);
        
        $this->invalidateRelatedKeys($cacheKey);
    }

    public function invalidatePattern(string $pattern): void
    {
        $keys = $this->getKeysByPattern($pattern);
        foreach ($keys as $key) {
            Cache::forget($key);
        }
    }

    public function has(string $key, ?SecurityContext $context = null): bool
    {
        $cacheKey = $this->generateSecureKey($key, $context);
        return Cache::has($cacheKey);
    }

    private function setCacheableEntity(string $key, CacheableEntity $entity, $ttl): void
    {
        $metadata = [
            'type' => get_class($entity),
            'created_at' => now()->timestamp,
            'checksum' => $this->calculateChecksum($entity)
        ];

        $data = [
            'metadata' => $metadata,
            'content' => $entity->toCacheArray()
        ];

        Cache::put($key, $data, $this->calculateTTL($ttl));
        $this->trackCacheKey($key, $metadata);
    }

    private function validateCachedData($data, ?SecurityContext $context): bool
    {
        if (!is_array($data) || !isset($data['metadata'])) {
            return true;
        }

        if (isset($data['metadata']['checksum'])) {
            $calculated = $this->calculateChecksum($data['content']);
            if ($calculated !== $data['metadata']['checksum']) {
                return false;
            }
        }

        if ($context && !$this->validateSecurityContext($data, $context)) {
            return false;
        }

        return !$this->isStale($data['metadata']);
    }

    private function generateSecureKey(string $key, ?SecurityContext $context): string
    {
        $parts = [$key];
        
        if ($context) {
            $parts[] = $context->getUserId();
            $parts[] = $context->getRoleHash();
        }
        
        return implode(':', array_filter($parts));
    }

    private function calculateTTL($ttl): int
    {
        if (is_null($ttl)) {
            return $this->config['default_ttl'] ?? 3600;
        }
        
        return min($ttl, $this->config['max_ttl'] ?? 86400);
    }

    private function calculateChecksum($data): string
    {
        return hash('xxh3', serialize($data));
    }

    private function isStale(array $metadata): bool
    {
        $maxAge = $this->config['max_age'] ?? 86400;
        return (now()->timestamp - $metadata['created_at']) > $maxAge;
    }

    private function validateSecurityContext(array $data, SecurityContext $context): bool
    {
        if (!isset($data['metadata']['permissions'])) {
            return true;
        }

        return $this->security->validatePermissions(
            $context,
            $data['metadata']['permissions']
        );
    }

    private function invalidateRelatedKeys(string $key): void
    {
        $pattern = $this->getRelatedKeysPattern($key);
        $this->invalidatePattern($pattern);
    }

    private function trackCacheKey(string $key, array $metadata): void
    {
        $tracking = Cache::get('cache_tracking', []);
        $tracking[$key] = [
            'metadata' => $metadata,
            'created_at' => now()->timestamp
        ];
        
        Cache::forever('cache_tracking', $tracking);
    }

    private function getKeysByPattern(string $pattern): array
    {
        $tracking = Cache::get('cache_tracking', []);
        return array_filter(array_keys($tracking), function($key) use ($pattern) {
            return fnmatch($pattern, $key);
        });
    }

    private function getRelatedKeysPattern(string $key): string
    {
        $parts = explode(':', $key);
        return $parts[0] . ':*';
    }
}
