<?php

namespace App\Core\Cache;

use Illuminate\Support\Facades\Cache;
use App\Core\Security\SecurityManager;
use App\Core\Metrics\MetricsCollector;

class CacheManager implements CacheInterface
{
    private SecurityManager $security;
    private MetricsCollector $metrics;
    private array $config;
    
    private const CACHE_VERSION = 'v1';
    private const MAX_KEY_LENGTH = 250;
    private const DEFAULT_TTL = 3600;

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $cacheKey = $this->generateCacheKey($key);
        $this->validateCacheKey($cacheKey);
        
        $startTime = microtime(true);
        
        try {
            if ($this->hasValidCache($cacheKey)) {
                $this->metrics->incrementHit($key);
                return $this->getFromCache($cacheKey);
            }

            $value = $callback();
            $this->setInCache($cacheKey, $value, $ttl);
            $this->metrics->incrementMiss($key);
            
            return $value;
        } finally {
            $this->recordMetrics($key, microtime(true) - $startTime);
        }
    }

    public function put(string $key, $value, ?int $ttl = null): void
    {
        $cacheKey = $this->generateCacheKey($key);
        $this->validateCacheKey($cacheKey);
        
        $this->setInCache($cacheKey, $value, $ttl);
    }

    public function forget(string $key): void
    {
        $cacheKey = $this->generateCacheKey($key);
        Cache::forget($cacheKey);
        $this->metrics->incrementEviction($key);
    }

    public function flush(string $pattern): void
    {
        $keys = $this->getMatchingKeys($pattern);
        foreach ($keys as $key) {
            $this->forget($key);
        }
    }

    public function tags(array $tags): TaggedCache
    {
        return new TaggedCache($this, $tags);
    }

    private function generateCacheKey(string $key): string
    {
        return sprintf(
            '%s:%s:%s',
            self::CACHE_VERSION,
            $this->config['prefix'],
            $this->normalizeKey($key)
        );
    }

    private function validateCacheKey(string $key): void
    {
        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw new CacheException('Cache key exceeds maximum length');
        }

        if (!$this->isValidKeyFormat($key)) {
            throw new CacheException('Invalid cache key format');
        }
    }

    private function hasValidCache(string $key): bool
    {
        if (!Cache::has($key)) {
            return false;
        }

        $metadata = $this->getCacheMetadata($key);
        return $this->isValidCacheData($metadata);
    }

    private function getFromCache(string $key): mixed
    {
        $data = Cache::get($key);
        $this->validateCacheData($data);
        return $data['value'];
    }

    private function setInCache(string $key, $value, ?int $ttl = null): void
    {
        $data = [
            'value' => $value,
            'metadata' => $this->generateMetadata($value)
        ];

        Cache::put(
            $key, 
            $data, 
            $ttl ?? $this->getDefaultTtl($key)
        );
    }

    private function normalizeKey(string $key): string
    {
        return preg_replace('/[^a-zA-Z0-9_.-]/', '_', $key);
    }

    private function isValidKeyFormat(string $key): bool
    {
        return preg_match('/^[a-zA-Z0-9_.-]+$/', $key);
    }

    private function generateMetadata($value): array
    {
        return [
            'checksum' => $this->calculateChecksum($value),
            'timestamp' => time(),
            'version' => self::CACHE_VERSION
        ];
    }

    private function calculateChecksum($value): string
    {
        return hash('xxh3', serialize($value));
    }

    private function validateCacheData($data): void
    {
        if (!isset($data['value']) || !isset($data['metadata'])) {
            throw new CacheException('Invalid cache data structure');
        }

        if (!$this->isValidChecksum($data['value'], $data['metadata']['checksum'])) {
            throw new CacheException('Cache data integrity check failed');
        }
    }

    private function isValidChecksum($value, string $storedChecksum): bool
    {
        $currentChecksum = $this->calculateChecksum($value);
        return hash_equals($currentChecksum, $storedChecksum);
    }

    private function getDefaultTtl(string $key): int
    {
        foreach ($this->config['ttl_rules'] as $pattern => $ttl) {
            if (fnmatch($pattern, $key)) {
                return $ttl;
            }
        }
        return self::DEFAULT_TTL;
    }

    private function recordMetrics(string $key, float $duration): void
    {
        $this->metrics->recordCacheOperation($key, [
            'duration' => $duration,
            'memory_usage' => memory_get_peak_usage(true),
            'memory_real_usage' => memory_get_peak_usage()
        ]);
    }

    private function getMatchingKeys(string $pattern): array
    {
        return Cache::getRedis()->keys($this->generateCacheKey($pattern));
    }

    private function getCacheMetadata(string $key): ?array
    {
        $data = Cache::get($key);
        return $data['metadata'] ?? null;
    }

    private function isValidCacheData(?array $metadata): bool
    {
        if (!$metadata) {
            return false;
        }

        if ($metadata['version'] !== self::CACHE_VERSION) {
            return false;
        }

        return true;
    }
}
