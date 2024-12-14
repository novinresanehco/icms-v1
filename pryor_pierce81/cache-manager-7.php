<?php

namespace App\Core\Cache;

class CriticalCacheManager
{
    private $store;
    private $security;
    private $monitor;

    const DEFAULT_TTL = 3600; // 1 hour

    public function get(string $key)
    {
        try {
            // Validate key
            if (!$this->security->validateCacheKey($key)) {
                throw new CacheException('Invalid cache key');
            }

            // Get from store with monitoring
            $start = microtime(true);
            $data = $this->store->get($key);
            $this->monitor->recordCacheRead($key, microtime(true) - $start);

            if (!$data) {
                return null;
            }

            // Decrypt and verify integrity
            return $this->security->decryptCacheData($data);

        } catch (\Exception $e) {
            $this->monitor->cacheFailure('read', $key, $e);
            return null;
        }
    }

    public function set(string $key, $value, int $ttl = self::DEFAULT_TTL): void
    {
        try {
            // Validate inputs
            $this->validateCacheInput($key, $value, $ttl);

            // Encrypt data
            $encrypted = $this->security->encryptCacheData($value);

            // Store with monitoring
            $start = microtime(true);
            $this->store->set($key, $encrypted, $ttl);
            $this->monitor->recordCacheWrite($key, microtime(true) - $start);

        } catch (\Exception $e) {
            $this->monitor->cacheFailure('write', $key, $e);
            throw $e;
        }
    }

    private function validateCacheInput(string $key, $value, int $ttl): void
    {
        if (strlen($key) > 250) {
            throw new CacheException('Cache key too long');
        }

        if ($ttl < 0) {
            throw new CacheException('Invalid TTL');
        }
    }
}
