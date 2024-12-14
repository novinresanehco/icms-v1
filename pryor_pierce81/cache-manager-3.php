<?php

namespace App\Core\Cache;

class CacheManager implements CacheInterface
{
    private CacheStore $store;
    private SecurityManager $security;
    private ValidationService $validator;
    private MetricsCollector $metrics;
    private array $config;

    public function __construct(
        CacheStore $store,
        SecurityManager $security,
        ValidationService $validator,
        MetricsCollector $metrics,
        array $config = []
    ) {
        $this->store = $store;
        $this->security = $security;
        $this->validator = $validator;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $startTime = microtime(true);

        try {
            if ($this->hasValidCache($key)) {
                $value = $this->retrieveFromCache($key);
                $this->metrics->recordCacheHit($key, microtime(true) - $startTime);
                return $value;
            }

            $value = DB::transaction(function() use ($callback) {
                $value = $callback();
                $this->validateCacheData($value);
                return $value;
            });

            $this->storeInCache($key, $value, $ttl);
            $this->metrics->recordCacheMiss($key, microtime(true) - $startTime);

            return $value;

        } catch (\Exception $e) {
            $this->handleCacheFailure($key, $e);
            throw new CacheException('Cache operation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function rememberForever(string $key, callable $callback): mixed
    {
        return $this->remember($key, 0, $callback);
    }

    public function forget(string $key): bool
    {
        try {
            return DB::transaction(function() use ($key) {
                $this->validateCacheKey($key);
                $this->store->forget($this->getSecureKey($key));
                $this->metrics->recordCacheInvalidation($key);
                return true;
            });
        } catch (\Exception $e) {
            $this->handleCacheFailure($key, $e);
            return false;
        }
    }

    public function tags(array $tags): TaggedCache
    {
        $this->validateTags($tags);
        return new TaggedCache($this->store, $this->security, $tags);
    }

    public function flush(): bool
    {
        return DB::transaction(function() {
            $this->store->flush();
            $this->metrics->recordCacheFlush();
            return true;
        });
    }

    private function hasValidCache(string $key): bool
    {
        $secureKey = $this->getSecureKey($key);
        if (!$this->store->has($secureKey)) {
            return false;
        }

        $metadata = $this->store->getMetadata($secureKey);
        return $this->validateCacheMetadata($metadata);
    }

    private function retrieveFromCache(string $key): mixed
    {
        $secureKey = $this->getSecureKey($key);
        $value = $this->store->get($secureKey);
        $this->validateCacheData($value);
        return $this->security->decryptData($value);
    }

    private function storeInCache(string $key, mixed $value, int $ttl): void
    {
        $secureKey = $this->getSecureKey($key);
        $encryptedValue = $this->security->encryptData($value);
        $metadata = $this->createCacheMetadata($key, $ttl);
        
        $this->store->put($secureKey, $encryptedValue, $ttl);
        $this->store->putMetadata($secureKey, $metadata);
    }

    private function validateCacheKey(string $key): void
    {
        if (!$this->validator->validateCacheKey($key)) {
            throw new InvalidCacheKeyException('Invalid cache key format');
        }
    }

    private function validateTags(array $tags): void
    {
        foreach ($tags as $tag) {
            if (!$this->validator->validateCacheTag($tag)) {
                throw new InvalidCacheTagException("Invalid cache tag: $tag");
            }
        }
    }

    private function validateCacheMetadata(array $metadata): bool
    {
        return $this->validator->validateCacheMetadata($metadata) &&
               $this->security->verifyMetadataIntegrity($metadata);
    }

    private function validateCacheData($data): void
    {
        if (!$this->validator->validateCacheData($data)) {
            throw new InvalidCacheDataException('Cache data validation failed');
        }
    }

    private function getSecureKey(string $key): string
    {
        return $this->security->hashKey($key);
    }

    private function createCacheMetadata(string $key, int $ttl): array
    {
        return [
            'key' => $key,
            'created_at' => time(),
            'ttl' => $ttl,
            'checksum' => $this->security->generateChecksum($key),
        ];
    }

    private function handleCacheFailure(string $key, \Exception $e): void
    {
        $this->metrics->recordCacheFailure($key, $e);
        $this->security->logSecurityEvent('cache_failure', [
            'key' => $key,
            'error' => $e->getMessage()
        ]);
    }
}
