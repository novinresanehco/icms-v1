<?php

namespace App\Core\Cache;

use Illuminate\Support\Facades\{Cache, Log};
use App\Core\Security\SecurityManager;

class CacheService
{
    private SecurityManager $security;
    private array $config;
    
    private const CACHE_DEFAULTS = [
        'ttl' => 3600,
        'prefix' => 'critical_cache:',
        'tags' => ['system']
    ];

    public function __construct(SecurityManager $security, array $config = [])
    {
        $this->security = $security;
        $this->config = array_merge(self::CACHE_DEFAULTS, $config);
    }

    /**
     * Store data in cache with security validation
     */
    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        try {
            $secureKey = $this->generateSecureKey($key);
            $secureValue = $this->prepareForCache($value);
            
            Cache::tags($this->config['tags'])->put(
                $secureKey,
                $secureValue,
                $ttl ?? $this->config['ttl']
            );
            
        } catch (\Throwable $e) {
            $this->handleCacheFailure('set', $key, $e);
        }
    }

    /**
     * Retrieve data from cache with validation
     */
    public function get(string $key): mixed
    {
        try {
            $secureKey = $this->generateSecureKey($key);
            
            $value = Cache::tags($this->config['tags'])->get($secureKey);
            
            if ($value === null) {
                return null;
            }

            return $this->validateAndDecodeCache($value);
            
        } catch (\Throwable $e) {
            $this->handleCacheFailure('get', $key, $e);
            return null;
        }
    }

    /**
     * Remember value in cache
     */
    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        try {
            $secureKey = $this->generateSecureKey($key);
            
            return Cache::tags($this->config['tags'])->remember(
                $secureKey,
                $ttl ?? $this->config['ttl'],
                function() use ($callback) {
                    return $this->prepareForCache($callback());
                }
            );
            
        } catch (\Throwable $e) {
            $this->handleCacheFailure('remember', $key, $e);
            return $callback();
        }
    }

    /**
     * Clear cache by key or pattern
     */
    public function clear(string $pattern = '*'): void
    {
        try {
            if ($pattern === '*') {
                Cache::tags($this->config['tags'])->flush();
            } else {
                $this->clearByPattern($pattern);
            }
        } catch (\Throwable $e) {
            $this->handleCacheFailure('clear', $pattern, $e);
        }
    }

    /**
     * Generate secure cache key
     */
    private function generateSecureKey(string $key): string
    {
        return $this->config['prefix'] . hash('sha256', $key);
    }

    /**
     * Prepare value for caching with security measures
     */
    private function prepareForCache(mixed $value): array
    {
        $prepared = [
            'data' => $value,
            'timestamp' => time(),
            'checksum' => $this->generateChecksum($value)
        ];

        return $this->security->encrypt($prepared);
    }

    /**
     * Validate and decode cached value
     */
    private function validateAndDecodeCache(mixed $value): mixed
    {
        $decoded = $this->security->decrypt($value);
        
        if (!$this->validateChecksum($decoded)) {
            throw new CacheException('Cache integrity check failed');
        }

        return $decoded['data'];
    }

    /**
     * Generate checksum for cache validation
     */
    private function generateChecksum(mixed $value): string
    {
        return hash('sha256', serialize($value));
    }

    /**
     * Validate cached data checksum
     */
    private function validateChecksum(array $data): bool
    {
        return hash_equals(
            $data['checksum'],
            $this->generateChecksum($data['data'])
        );
    }

    /**
     * Clear cache by pattern
     */
    private function clearByPattern(string $pattern): void
    {
        $keys = Cache::tags($this->config['tags'])
            ->getStore()
            ->keys($this->config['prefix'] . $pattern);

        foreach ($keys as $key) {
            Cache::tags($this->config['tags'])->forget($key);
        }
    }

    /**
     * Handle cache operation failures
     */
    private function handleCacheFailure(string $operation, string $key, \Throwable $e): void
    {
        Log::error("Cache {$operation} failed", [
            'key' => $key,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->security->notifyAdministrators('cache_failure', [
            'operation' => $operation,
            'key' => $key,
            'error' => $e->getMessage()
        ]);
    }
}
