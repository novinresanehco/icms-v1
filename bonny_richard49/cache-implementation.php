<?php

namespace App\Core\Cache;

class SecureCacheManager implements CacheInterface
{
    private SecurityManager $security;
    private CacheStore $store;
    private Serializer $serializer;
    private AuditLogger $logger;
    private Config $config;

    public function get(string $key, SecurityContext $context): mixed
    {
        try {
            // Validate access
            $this->validateAccess($key, $context);
            
            // Get from cache
            $cached = $this->store->get($this->getSecureKey($key));
            
            if ($cached === null) {
                return null;
            }
            
            // Decrypt and unserialize
            $value = $this->deserialize($cached);
            
            // Verify integrity
            $this->verifyIntegrity($value, $context);
            
            return $value;

        } catch (\Exception $e) {
            $this->handleCacheFailure('get', $key, $e);
            throw $e;
        }
    }

    public function put(string $key, mixed $value, SecurityContext $context): void
    {
        try {
            // Validate access
            $this->validateAccess($key, $context);
            
            // Serialize and encrypt
            $serialized = $this->serialize($value);
            
            // Store with TTL
            $this->store->put(
                $this->getSecureKey($key),
                $serialized,
                $this->getTTL()
            );
            
            // Log cache write
            $this->logger->logCacheWrite($key, $context);

        } catch (\Exception $e) {
            $this->handleCacheFailure('put', $key, $e);
            throw $e;
        }
    }

    public function forget(string $key, SecurityContext $context): void
    {
        try {
            // Validate access
            $this->validateAccess($key, $context);
            
            // Remove from cache
            $this->store->forget($this->getSecureKey($key));
            
            // Log cache delete
            $this->logger->logCacheDelete($key, $context);

        } catch (\Exception $e) {
            $this->handleCacheFailure('forget', $key, $e);
            throw $e;
        }
    }

    private function validateAccess(string $key, SecurityContext $context): void
    {
        if (!$this->security->validateCacheAccess($key, $context)) {
            throw new SecurityException('Cache access denied');
        }
    }

    private function getSecureKey(string $key): string
    {
        return $this->security->hashKey($key);
    }

    private function serialize(mixed $value): string
    {
        return $this->serializer->serialize($value);
    }

    private function deserialize(string $value): mixed
    {
        return $this->serializer->unserialize($value);
    }

    private function verifyIntegrity(mixed $value, SecurityContext $context): void
    {
        if (!$this->security->verifyIntegrity($value, $context)) {
            throw new SecurityException('Cache integrity check failed');
        }
    }

    private function getTTL(): int
    {
        return $this->config->get('cache.ttl', 3600);
    }
}

class CacheStore implements CacheStoreInterface
{
    private Redis $redis;
    private BackupStore $backup;
    private MonitoringService $monitor;
    private Config $config;

    public function get(string $key): ?string
    {
        try {
            // Try primary store
            if ($value = $this->redis->get($key)) {
                return $value;
            }
            
            // Check backup store
            if ($value = $this->backup->get($key)) {
                $this->restoreToRedis($key, $value);
                return $value;
            }
            
            return null;

        } catch (\Exception $e) {
            $this->handleStoreFailure('get', $key, $e);
            throw $e;
        }
    }

    public function put(string $key, string $value, int $ttl): void
    {
        try {
            // Store in Redis
            $this->redis->setex($key, $ttl, $value);
            
            // Backup if configured
            if ($this->shouldBackup()) {
                $this->backup->put($key, $value, $ttl);
            }
            
            // Monitor storage
            $this->monitor->trackCacheStorage($key, strlen($value));

        } catch (\Exception $e) {
            $this->handleStoreFailure('put', $key, $e);
            throw $e;
        }
    }

    public function forget(string $key): void
    {
        try {
            // Remove from Redis
            $this->redis->del($key);
            
            // Remove from backup
            $this->backup->forget($key);
            
            // Monitor deletion
            $this->monitor->trackCacheDeletion($key);

        } catch (\Exception $e) {
            $this->handleStoreFailure('forget', $key, $e);
            throw $e;
        }
    }

    private function shouldBackup(): bool
    {
        return $this->config->get('cache.backup.enabled', true);
    }

    private function restoreToRedis(string $key, string $value): void
    {
        $ttl = $this->backup->getTTL($key);
        $this->redis->setex($key, $ttl, $value);
    }
}