<?php

namespace App\Core\Cache;

use App\Core\Security\CoreSecurityManager;
use App\Core\Services\ValidationService;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Log;
use App\Core\Exceptions\{CacheException, SecurityException};

class CacheManager implements CacheManagerInterface
{
    private CacheRepository $cache;
    private CoreSecurityManager $security;
    private ValidationService $validator;
    private array $config;
    
    private const CACHE_VERSION = 'v1';
    private const MAX_KEY_LENGTH = 250;
    private const DEFAULT_TTL = 3600;

    public function __construct(
        CacheRepository $cache,
        CoreSecurityManager $security,
        ValidationService $validator,
        array $config
    ) {
        $this->cache = $cache;
        $this->security = $security;
        $this->validator = $validator;
        $this->config = $config;
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        return $this->security->executeSecureOperation(
            function() use ($key, $callback, $ttl) {
                $secureKey = $this->generateSecureKey($key);
                $this->validateKey($secureKey);
                
                if ($this->shouldRefresh($secureKey)) {
                    return $this->refreshCache($secureKey, $callback, $ttl);
                }

                return $this->getFromCache($secureKey) ?? 
                    $this->refreshCache($secureKey, $callback, $ttl);
            },
            ['action' => 'cache_read', 'key' => $key]
        );
    }

    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->security->executeSecureOperation(
            function() use ($key, $value, $ttl) {
                $secureKey = $this->generateSecureKey($key);
                $this->validateKey($secureKey);
                $this->validateValue($value);
                
                $ttl = $ttl ?? $this->getDefaultTtl($key);
                $metadata = $this->generateMetadata($value);
                
                return $this->cache->put(
                    $secureKey,
                    $this->wrapValue($value, $metadata),
                    $ttl
                );
            },
            ['action' => 'cache_write', 'key' => $key]
        );
    }

    public function invalidate(string $key): bool
    {
        return $this->security->executeSecureOperation(
            function() use ($key) {
                $secureKey = $this->generateSecureKey($key);
                return $this->cache->forget($secureKey);
            },
            ['action' => 'cache_invalidate', 'key' => $key]
        );
    }

    public function tags(array $tags): TaggedCache
    {
        $validatedTags = array_map(
            fn($tag) => $this->generateSecureKey($tag),
            $this->validator->validateTags($tags)
        );
        
        return new TaggedCache(
            $this->cache->tags($validatedTags),
            $this->security,
            $this->validator
        );
    }

    protected function generateSecureKey(string $key): string
    {
        $hash = hash('sha256', $key);
        return sprintf(
            '%s:%s:%s',
            self::CACHE_VERSION,
            substr($hash, 0, 10),
            $key
        );
    }

    protected function validateKey(string $key): void
    {
        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw new CacheException('Cache key too long');
        }

        if (!$this->validator->validateCacheKey($key)) {
            throw new SecurityException('Invalid cache key format');
        }
    }

    protected function validateValue($value): void
    {
        if (!$this->validator->validateCacheValue($value)) {
            throw new SecurityException('Invalid cache value type');
        }
    }

    protected function shouldRefresh(string $key): bool
    {
        $metadata = $this->getMetadata($key);
        if (!$metadata) {
            return true;
        }

        return $this->isStale($metadata) || 
            $this->isCompromised($metadata);
    }

    protected function refreshCache(string $key, callable $callback, ?int $ttl): mixed
    {
        try {
            $value = $callback();
            $this->validateValue($value);
            
            $ttl = $ttl ?? $this->getDefaultTtl($key);
            $metadata = $this->generateMetadata($value);
            
            $this->cache->put(
                $key,
                $this->wrapValue($value, $metadata),
                $ttl
            );
            
            return $value;
            
        } catch (\Exception $e) {
            Log::error('Cache refresh failed', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            throw new CacheException('Failed to refresh cache');
        }
    }

    protected function getFromCache(string $key): mixed
    {
        $wrapped = $this->cache->get($key);
        if (!$wrapped) {
            return null;
        }

        if (!$this->validateWrappedValue($wrapped)) {
            $this->invalidate($key);
            return null;
        }

        return $wrapped['value'];
    }

    protected function wrapValue($value, array $metadata): array
    {
        return [
            'value' => $value,
            'metadata' => $metadata,
            'checksum' => $this->calculateChecksum($value)
        ];
    }

    protected function generateMetadata($value): array
    {
        return [
            'created_at' => time(),
            'type' => gettype($value),
            'size' => $this->calculateSize($value),
            'hash' => $this->calculateHash($value)
        ];
    }

    protected function isStale(array $metadata): bool
    {
        $maxAge = $this->config['max_age'] ?? 86400;
        return (time() - $metadata['created_at']) > $maxAge;
    }

    protected function isCompromised(array $metadata): bool
    {
        return !$this->validator->validateMetadata($metadata);
    }

    protected function getDefaultTtl(string $key): int
    {
        return $this->config['ttl'][$key] ?? self::DEFAULT_TTL;
    }

    protected function calculateChecksum($value): string
    {
        return hash('sha256', serialize($value));
    }

    protected function calculateSize($value): int
    {
        return strlen(serialize($value));
    }

    protected function calculateHash($value): string
    {
        return hash('sha256', serialize($value));
    }

    protected function validateWrappedValue(array $wrapped): bool
    {
        return isset($wrapped['value'], $wrapped['metadata'], $wrapped['checksum']) &&
            $wrapped['checksum'] === $this->calculateChecksum($wrapped['value']);
    }
}
