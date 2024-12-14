<?php

namespace App\Core\Performance;

class CacheManager implements CacheInterface
{
    private CacheStore $store;
    private EncryptionService $encryption;
    private CompressionService $compression;
    private MetricsCollector $metrics;
    private SecurityValidator $validator;

    public function __construct(
        CacheStore $store,
        EncryptionService $encryption,
        CompressionService $compression,
        MetricsCollector $metrics,
        SecurityValidator $validator
    ) {
        $this->store = $store;
        $this->encryption = $encryption;
        $this->compression = $compression;
        $this->metrics = $metrics;
        $this->validator = $validator;
    }

    public function remember(string $key, callable $callback, int $ttl = 3600): mixed
    {
        $this->validateKey($key);
        $this->metrics->incrementAccess($key);
        
        $cacheKey = $this->generateSecureKey($key);
        
        if ($value = $this->get($cacheKey)) {
            $this->metrics->incrementHit($key);
            return $value;
        }

        $this->metrics->incrementMiss($key);
        
        $value = $callback();
        $this->set($cacheKey, $value, $ttl);
        
        return $value;
    }

    public function get(string $key): mixed
    {
        $this->validateKey($key);
        $startTime = microtime(true);
        
        try {
            $encrypted = $this->store->get($key);
            
            if (!$encrypted) {
                return null;
            }

            $compressed = $this->encryption->decrypt($encrypted);
            $value = $this->compression->decompress($compressed);
            
            if (!$this->validator->validateData($value)) {
                $this->store->forget($key);
                return null;
            }

            $this->metrics->recordLatency($key, microtime(true) - $startTime);
            return $value;
            
        } catch (\Exception $e) {
            $this->handleError($key, $e);
            return null;
        }
    }

    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        $this->validateKey($key);
        $startTime = microtime(true);
        
        try {
            if (!$this->validator->validateData($value)) {
                throw new InvalidDataException();
            }

            $compressed = $this->compression->compress($value);
            $encrypted = $this->encryption->encrypt($compressed);
            
            $success = $this->store->put($key, $encrypted, $ttl);
            
            $this->metrics->recordLatency($key, microtime(true) - $startTime);
            return $success;
            
        } catch (\Exception $e) {
            $this->handleError($key, $e);
            return false;
        }
    }

    public function forget(string $key): bool
    {
        $this->validateKey($key);
        
        try {
            return $this->store->forget($key);
            
        } catch (\Exception $e) {
            $this->handleError($key, $e);
            return false;
        }
    }

    public function tags(array $tags): TaggedCache
    {
        array_walk($tags, [$this, 'validateKey']);
        
        return new TaggedCache(
            $this->store,
            $this->encryption,
            $this->compression,
            $this->metrics,
            $this->validator,
            $tags
        );
    }

    public function flush(): bool
    {
        try {
            return $this->store->flush();
            
        } catch (\Exception $e) {
            $this->handleError('flush', $e);
            return false;
        }
    }

    private function validateKey(string $key): void
    {
        if (!$this->validator->validateKey($key)) {
            throw new InvalidKeyException();
        }
    }

    private function generateSecureKey(string $key): string
    {
        return hash_hmac('sha256', $key, config('app.key'));
    }

    private function handleError(string $key, \Exception $e): void
    {
        $this->metrics->incrementError($key);
        
        Log::error('Cache operation failed', [
            'key' => $key,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($e instanceof SecurityException) {
            $this->store->forget($key);
        }
    }
}

class TaggedCache
{
    private CacheStore $store;
    private EncryptionService $encryption;
    private CompressionService $compression;
    private MetricsCollector $metrics;
    private SecurityValidator $validator;
    private array $tags;

    public function __construct(
        CacheStore $store,
        EncryptionService $encryption,
        CompressionService $compression,
        MetricsCollector $metrics,
        SecurityValidator $validator,
        array $tags
    ) {
        $this->store = $store;
        $this->encryption = $encryption;
        $this->compression = $compression;
        $this->metrics = $metrics;
        $this->validator = $validator;
        $this->tags = $tags;
    }

    public function get(string $key): mixed
    {
        $taggedKey = $this->generateTaggedKey($key);
        return $this->store->get($taggedKey);
    }

    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        $taggedKey = $this->generateTaggedKey($key);
        return $this->store->put($taggedKey, $value, $ttl);
    }

    public function flush(): bool
    {
        return $this->store->tags($this->tags)->flush();
    }

    private function generateTaggedKey(string $key): string
    {
        $tagString = implode(':', $this->tags);
        return "{$tagString}:{$key}";
    }
}

interface CacheInterface
{
    public function remember(string $key, callable $callback, int $ttl = 3600): mixed;
    public function get(string $key): mixed;
    public function set(string $key, mixed $value, int $ttl = 3600): bool;
    public function forget(string $key): bool;
    public function tags(array $tags): TaggedCache;
    public function flush(): bool;
}
