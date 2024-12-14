<?php

namespace App\Core\Services;

use Illuminate\Support\Facades\{Cache, Log, Redis};
use App\Core\Services\{SecurityService, ValidationService};
use App\Core\Exceptions\{CacheException, SecurityException};

class CacheManager
{
    private SecurityService $security;
    private ValidationService $validator;
    private array $cacheConfig;
    private array $metrics = [];

    private const DEFAULT_TTL = 3600;
    private const LOCK_TIMEOUT = 30;
    private const RETRY_ATTEMPTS = 3;
    private const BACKOFF_MS = 100;

    public function __construct(
        SecurityService $security,
        ValidationService $validator,
        array $cacheConfig
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cacheConfig = $cacheConfig;
    }

    public function remember(string $key, $value, ?int $ttl = null)
    {
        $ttl = $ttl ?? self::DEFAULT_TTL;
        $this->validateCacheKey($key);

        return Cache::remember($key, $ttl, function() use ($value, $key) {
            try {
                $data = is_callable($value) ? $value() : $value;
                $this->validateCacheData($data);
                
                $encrypted = $this->encryptCacheData($data);
                $this->trackMetrics('cache.write', $key);
                
                return $encrypted;
                
            } catch (\Exception $e) {
                $this->handleCacheError('write', $key, $e);
                throw new CacheException('Cache write failed: ' . $e->getMessage());
            }
        });
    }

    public function get(string $key)
    {
        $this->validateCacheKey($key);

        try {
            $encryptedData = Cache::get($key);
            
            if ($encryptedData === null) {
                $this->trackMetrics('cache.miss', $key);
                return null;
            }

            $data = $this->decryptCacheData($encryptedData);
            $this->validateCacheData($data);
            
            $this->trackMetrics('cache.hit', $key);
            return $data;
            
        } catch (\Exception $e) {
            $this->handleCacheError('read', $key, $e);
            throw new CacheException('Cache read failed: ' . $e->getMessage());
        }
    }

    public function put(string $key, $value, ?int $ttl = null): bool
    {
        $ttl = $ttl ?? self::DEFAULT_TTL;
        $this->validateCacheKey($key);
        
        try {
            $this->validateCacheData($value);
            $encrypted = $this->encryptCacheData($value);
            
            $success = Cache::put($key, $encrypted, $ttl);
            $this->trackMetrics('cache.write', $key);
            
            return $success;
            
        } catch (\Exception $e) {
            $this->handleCacheError('write', $key, $e);
            throw new CacheException('Cache write failed: ' . $e->getMessage());
        }
    }

    public function tags(array $tags): self
    {
        foreach ($tags as $tag) {
            $this->validateCacheKey($tag);
        }
        
        return new static(
            $this->security,
            $this->validator,
            array_merge($this->cacheConfig, ['tags' => $tags])
        );
    }

    public function atomic(string $key, callable $callback, int $ttl = null)
    {
        $lockKey = "lock:{$key}";
        $attempts = 0;

        while ($attempts < self::RETRY_ATTEMPTS) {
            try {
                if ($this->acquireLock($lockKey)) {
                    try {
                        $result = $callback();
                        $this->put($key, $result, $ttl);
                        return $result;
                    } finally {
                        $this->releaseLock($lockKey);
                    }
                }
                
                usleep(self::BACKOFF_MS * 1000 * (2 ** $attempts));
                $attempts++;
                
            } catch (\Exception $e) {
                $this->handleCacheError('atomic', $key, $e);
                throw new CacheException('Atomic operation failed: ' . $e->getMessage());
            }
        }

        throw new CacheException('Failed to acquire lock after ' . self::RETRY_ATTEMPTS . ' attempts');
    }

    public function flush(string $pattern = '*'): bool
    {
        try {
            if (isset($this->cacheConfig['tags'])) {
                Cache::tags($this->cacheConfig['tags'])->flush();
            } else {
                $this->flushByPattern($pattern);
            }
            
            $this->trackMetrics('cache.flush', $pattern);
            return true;
            
        } catch (\Exception $e) {
            $this->handleCacheError('flush', $pattern, $e);
            throw new CacheException('Cache flush failed: ' . $e->getMessage());
        }
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    protected function validateCacheKey(string $key): void
    {
        if (empty($key) || strlen($key) > 250) {
            throw new CacheException('Invalid cache key');
        }

        if (!preg_match('/^[\w\-\:\.]+$/', $key)) {
            throw new SecurityException('Invalid cache key format');
        }
    }

    protected function validateCacheData($data): void
    {
        if (is_resource($data)) {
            throw new CacheException('Cannot cache resource type');
        }

        $serialized = serialize($data);
        if (strlen($serialized) > $this->cacheConfig['max_size'] ?? 1048576) {
            throw new CacheException('Cache data too large');
        }
    }

    protected function encryptCacheData($data): string
    {
        return $this->security->encrypt(serialize($data));
    }

    protected function decryptCacheData(string $encrypted)
    {
        return unserialize($this->security->decrypt($encrypted));
    }

    protected function acquireLock(string $key): bool
    {
        return Redis::set(
            $key,
            1,
            'EX',
            self::LOCK_TIMEOUT,
            'NX'
        );
    }

    protected function releaseLock(string $key): void
    {
        Redis::del($key);
    }

    protected function flushByPattern(string $pattern): void
    {
        $keys = Redis::keys($pattern);
        if (!empty($keys)) {
            Redis::del($keys);
        }
    }

    protected function trackMetrics(string $type, string $key): void
    {
        $this->metrics[] = [
            'type' => $type,
            'key' => $key,
            'timestamp' => microtime(true),
            'memory' => memory_get_usage(true)
        ];
    }

    protected function handleCacheError(string $operation, string $key, \Exception $e): void
    {
        Log::error('Cache operation failed', [
            'operation' => $operation,
            'key' => $key,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
