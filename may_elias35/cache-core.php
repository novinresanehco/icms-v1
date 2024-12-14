<?php

namespace App\Core\Cache;

class CacheManager implements CacheInterface
{
    private CacheStore $store;
    private SecurityManager $security;
    private ValidationService $validator;
    private IntegrityChecker $integrity;
    private MetricsCollector $metrics;
    private AuditLogger $logger;

    public function __construct(
        CacheStore $store,
        SecurityManager $security,
        ValidationService $validator,
        IntegrityChecker $integrity,
        MetricsCollector $metrics,
        AuditLogger $logger
    ) {
        $this->store = $store;
        $this->security = $security;
        $this->validator = $validator;
        $this->integrity = $integrity;
        $this->metrics = $metrics;
        $this->logger = $logger;
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        $operationId = uniqid('cache_', true);
        $startTime = microtime(true);

        try {
            $this->validateCacheOperation($key);
            $this->security->validateAccess($key);

            if ($cached = $this->getFromCache($key)) {
                $this->metrics->recordCacheHit($key);
                return $cached;
            }

            $value = $this->generateCacheValue($callback);
            $this->storeInCache($key, $value, $ttl);

            $this->metrics->recordCacheMiss($key);
            $this->logCacheOperation($operationId, 'set', $key, microtime(true) - $startTime);

            return $value;

        } catch (\Exception $e) {
            $this->handleCacheFailure($operationId, $key, $e);
            throw new CacheException('Cache operation failed', 0, $e);
        }
    }

    private function validateCacheOperation(string $key): void
    {
        if (!$this->validator->validateCacheKey($key)) {
            throw new InvalidKeyException('Invalid cache key format');
        }

        if ($this->security->isBlacklisted($key)) {
            throw new SecurityException('Cache key is blacklisted');
        }
    }

    private function getFromCache(string $key): mixed
    {
        $encrypted = $this->store->get($this->getSecureKey($key));
        
        if (!$encrypted) {
            return null;
        }

        try {
            $decrypted = $this->security->decrypt($encrypted);
            $data = unserialize($decrypted);

            if (!$this->integrity->verifyData($data, $key)) {
                $this->handleIntegrityFailure($key);
                return null;
            }

            return $data;

        } catch (\Exception $e) {
            $this->handleDecryptionFailure($key, $e);
            return null;
        }
    }

    private function generateCacheValue(callable $callback): mixed
    {
        try {
            $value = $callback();
            
            if (!$this->validator->validateCacheValue($value)) {
                throw new ValidationException('Invalid cache value generated');
            }

            return $value;

        } catch (\Exception $e) {
            $this->logger->logGenerationFailure($e);
            throw $e;
        }
    }

    private function storeInCache(string $key, mixed $value, int $ttl): void
    {
        $serialized = serialize($value);
        $encrypted = $this->security->encrypt($serialized);
        $secureKey = $this->getSecureKey($key);

        $stored = $this->store->put($secureKey, $encrypted, $ttl);

        if (!$stored) {
            throw new StorageException('Failed to store in cache');
        }

        $this->integrity->recordDataHash($value, $key);
    }

    private function getSecureKey(string $key): string
    {
        return hash_hmac('sha256', $key, $this->security->getSecretKey());
    }

    public function forget(string $key): bool
    {
        try {
            $this->validateCacheOperation($key);
            $this->security->validateAccess($key);

            $secureKey = $this->getSecureKey($key);
            $forgotten = $this->store->forget($secureKey);

            if ($forgotten) {
                $this->integrity->removeDataHash($key);
                $this->logger->logCacheInvalidation($key);
            }

            return $forgotten;

        } catch (\Exception $e) {
            $this->logger->logInvalidationFailure($key, $e);
            throw new CacheException('Cache invalidation failed', 0, $e);
        }
    }

    public function flush(): bool
    {
        try {
            $this->security->validateAdminAccess();
            
            $flushed = $this->store->flush();
            
            if ($flushed) {
                $this->integrity->resetDataHashes();
                $this->logger->logCacheFlush();
            }

            return $flushed;

        } catch (\Exception $e) {
            $this->logger->logFlushFailure($e);
            throw new CacheException('Cache flush failed', 0, $e);
        }
    }

    private function handleCacheFailure(string $operationId, string $key, \Exception $e): void
    {
        $this->logger->logCacheFailure([
            'operation_id' => $operationId,
            'key' => $key,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => now()
        ]);

        if ($e instanceof SecurityException) {
            $this->security->handleSecurityIncident($operationId, $e);
        }
    }

    private function handleIntegrityFailure(string $key): void
    {
        $this->logger->logIntegrityFailure($key);
        $this->forget($key);
        $this->security->reportIntegrityBreach($key);
    }

    private function handleDecryptionFailure(string $key, \Exception $e): void
    {
        $this->logger->logDecryptionFailure($key, $e);
        $this->forget($key);
        $this->security->reportDecryptionFailure($key);
    }

    private function logCacheOperation(string $operationId, string $type, string $key, float $duration): void
    {
        $this->logger->logCache([
            'operation_id' => $operationId,
            'type' => $type,
            'key' => $key,
            'duration' => $duration,
            'timestamp' => now()
        ]);
    }
}
