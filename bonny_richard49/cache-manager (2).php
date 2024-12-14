<?php

namespace App\Core\Cache;

use App\Core\Security\CoreSecurityManager;
use App\Core\Services\ValidationService;
use App\Core\Services\AuditService;
use Illuminate\Support\Facades\Cache;
use App\Core\Exceptions\CacheException;

class CacheManager
{
    private CoreSecurityManager $security;
    private ValidationService $validator;
    private AuditService $audit;
    private array $config;

    private const DEFAULT_TTL = 3600;
    private const CACHE_VERSION = 'v1';

    public function __construct(
        CoreSecurityManager $security,
        ValidationService $validator,
        AuditService $audit,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function remember(string $key, $value, ?int $ttl = null): mixed
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeRemember($key, $value, $ttl),
            ['operation' => 'cache_remember', 'key' => $key]
        );
    }

    public function get(string $key): mixed
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeGet($key),
            ['operation' => 'cache_get', 'key' => $key]
        );
    }

    public function put(string $key, $value, ?int $ttl = null): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executePut($key, $value, $ttl),
            ['operation' => 'cache_put', 'key' => $key]
        );
    }

    public function invalidate(string $key): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeInvalidate($key),
            ['operation' => 'cache_invalidate', 'key' => $key]
        );
    }

    public function tags(array $tags): TaggedCache
    {
        return new TaggedCache($this, $tags);
    }

    private function executeRemember(string $key, $value, ?int $ttl): mixed
    {
        $cacheKey = $this->generateCacheKey($key);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        try {
            // Check if value exists in cache
            if (Cache::has($cacheKey)) {
                $cached = Cache::get($cacheKey);
                
                // Validate cached data integrity
                if ($this->validateCachedData($cached)) {
                    return $this->unserializeData($cached);
                }
            }

            // Generate value if callback provided
            $result = is_callable($value) ? $value() : $value;

            // Store in cache
            $this->executePut($key, $result, $ttl);

            return $result;

        } catch (\Exception $e) {
            $this->handleCacheError($e, $key);
            return is_callable($value) ? $value() : $value;
        }
    }

    private function executeGet(string $key): mixed
    {
        $cacheKey = $this->generateCacheKey($key);

        try {
            if (!Cache::has($cacheKey)) {
                return null;
            }

            $cached = Cache::get($cacheKey);
            
            // Validate data integrity
            if (!$this->validateCachedData($cached)) {
                $this->invalidate($key);
                return null;
            }

            return $this->unserializeData($cached);

        } catch (\Exception $e) {
            $this->handleCacheError($e, $key);
            return null;
        }
    }

    private function executePut(string $key, $value, ?int $ttl): bool
    {
        $cacheKey = $this->generateCacheKey($key);
        $ttl = $ttl ?? self::DEFAULT_TTL;

        try {
            // Prepare value for caching
            $prepared = $this->prepareForCache($value);
            
            // Store with integrity check
            return Cache::put($cacheKey, $prepared, $ttl);

        } catch (\Exception $e) {
            $this->handleCacheError($e, $key);
            return false;
        }
    }

    private function executeInvalidate(string $key): bool
    {
        $cacheKey = $this->generateCacheKey($key);

        try {
            return Cache::forget($cacheKey);
        } catch (\Exception $e) {
            $this->handleCacheError($e, $key);
            return false;
        }
    }

    private function generateCacheKey(string $key): string
    {
        return sprintf(
            '%s:%s:%s',
            self::CACHE_VERSION,
            $this->config['cache_prefix'] ?? 'app',
            $key
        );
    }

    private function prepareForCache($value): string
    {
        $serialized = $this->serializeData($value);
        $hash = $this->generateHash($serialized);

        return json_encode([
            'data' => $serialized,
            'hash' => $hash,
            'timestamp' => time()
        ]);
    }

    private function validateCachedData($cached): bool
    {
        try {
            $data = json_decode($cached, true);
            
            if (!isset($data['data'], $data['hash'])) {
                return false;
            }

            return hash_equals(
                $data['hash'],
                $this->generateHash($data['data'])
            );

        } catch (\Exception $e) {
            return false;
        }
    }

    private function serializeData($value): string
    {
        return base64_encode(serialize($value));
    }

    private function unserializeData($cached): mixed
    {
        $data = json_decode($cached, true);
        return unserialize(base64_decode($data['data']));
    }

    private function generateHash(string $data): string
    {
        return hash_hmac(
            'sha256',
            $data,
            $this->config['cache_key']
        );
    }

    private function handleCacheError(\Exception $e, string $key): void
    {
        $this->audit->logFailure($e, [
            'key' => $key,
            'operation' => 'cache_operation'
        ]);

        if ($this->config['throw_exceptions'] ?? false) {
            throw new CacheException(
                'Cache operation failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
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

    public function remember(string $key, $value, ?int $ttl = null): mixed
    {
        return $this->cache->remember(
            $this->generateTaggedKey($key),
            $value,
            $ttl
        );
    }

    public function flush(): bool
    {
        return Cache::tags($this->tags)->flush();
    }

    private function generateTaggedKey(string $key): string
    {
        return implode(':', array_merge($this->tags, [$key]));
    }
}