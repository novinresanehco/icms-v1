<?php

namespace App\Core\Cache;

use Illuminate\Support\Facades\Redis;
use App\Core\Security\Encryption\EncryptionService;
use App\Core\Security\Validation\ValidationService;
use App\Core\Audit\AuditLogger;
use App\Exceptions\CacheException;

class SecureCacheManager implements CacheInterface
{
    private EncryptionService $encryption;
    private ValidationService $validator;
    private AuditLogger $auditLogger;
    private array $config;

    private const DEFAULT_TTL = 3600;
    private const CACHE_VERSION = 'v1';
    private const MAX_KEY_LENGTH = 250;
    private const INTEGRITY_SUFFIX = ':integrity';

    public function secureSet(string $key, mixed $value, ?int $ttl = null): bool
    {
        try {
            // Validate inputs
            $this->validateKey($key);
            $this->validateValue($value);

            // Generate cache key
            $cacheKey = $this->generateCacheKey($key);

            // Serialize and encrypt value
            $serialized = serialize($value);
            $encrypted = $this->encryption->encryptData($serialized);

            // Calculate integrity hash
            $integrity = $this->calculateIntegrity($encrypted->data);

            // Store in cache
            $success = Redis::pipeline(function($pipe) use ($cacheKey, $encrypted, $integrity, $ttl) {
                $pipe->set($cacheKey, $encrypted->data, 'EX', $ttl ?? self::DEFAULT_TTL);
                $pipe->set($cacheKey . self::INTEGRITY_SUFFIX, $integrity, 'EX', $ttl ?? self::DEFAULT_TTL);
            });

            if (!$success) {
                throw new CacheException("Failed to store cache data for key: $key");
            }

            $this->auditLogger->logSecurityEvent(
                'cache_write',
                ['key' => $key],
                1 // Low severity
            );

            return true;

        } catch (\Throwable $e) {
            $this->handleCacheFailure('set', $e, $key);
            throw new CacheException("Cache set operation failed for key: $key", 0, $e);
        }
    }

    public function secureGet(string $key): mixed
    {
        try {
            // Validate key
            $this->validateKey($key);

            // Generate cache key
            $cacheKey = $this->generateCacheKey($key);

            // Get from cache
            [$encrypted, $storedIntegrity] = Redis::pipeline(function($pipe) use ($cacheKey) {
                $pipe->get($cacheKey);
                $pipe->get($cacheKey . self::INTEGRITY_SUFFIX);
            });

            if (!$encrypted || !$storedIntegrity) {
                return null;
            }

            // Verify integrity
            $currentIntegrity = $this->calculateIntegrity($encrypted);
            if (!hash_equals($currentIntegrity, $storedIntegrity)) {
                $this->handleIntegrityFailure($key);
                throw new CacheException("Cache integrity check failed for key: $key");
            }

            // Decrypt and unserialize
            $decrypted = $this->encryption->decryptData(new EncryptedData($encrypted));
            $value = unserialize($decrypted);

            $this->auditLogger->logSecurityEvent(
                'cache_read',
                ['key' => $key],
                1 // Low severity
            );

            return $value;

        } catch (\Throwable $e) {
            $this->handleCacheFailure('get', $e, $key);
            throw new CacheException("Cache get operation failed for key: $key", 0, $e);
        }
    }

    public function secureDelete(string $key): bool
    {
        try {
            // Validate key
            $this->validateKey($key);

            // Generate cache key
            $cacheKey = $this->generateCacheKey($key);

            // Delete from cache
            $deleted = Redis::pipeline(function($pipe) use ($cacheKey) {
                $pipe->del($cacheKey);
                $pipe->del($cacheKey . self::INTEGRITY_SUFFIX);
            });

            $this->auditLogger->logSecurityEvent(
                'cache_delete',
                ['key' => $key],
                1 // Low severity
            );

            return $deleted > 0;

        } catch (\Throwable $e) {
            $this->handleCacheFailure('delete', $e, $key);
            throw new CacheException("Cache delete operation failed for key: $key", 0, $e);
        }
    }

    public function secureFlush(): bool
    {
        try {
            // Get all keys
            $keys = Redis::keys($this->generateCacheKey('*'));
            
            if (empty($keys)) {
                return true;
            }

            // Delete all keys in batches
            foreach (array_chunk($keys, 1000) as $keysBatch) {
                Redis::del($keysBatch);
            }

            $this->auditLogger->logSecurityEvent(
                'cache_flush',
                ['keys_count' => count($keys)],
                2 // Medium severity
            );

            return true;

        } catch (\Throwable $e) {
            $this->handleCacheFailure('flush', $e);
            throw new CacheException("Cache flush operation failed", 0, $e);
        }
    }

    private function validateKey(string $key): void
    {
        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw new CacheException("Cache key exceeds maximum length");
        }

        if (!preg_match('/^[a-zA-Z0-9:._-]+$/', $key)) {
            throw new CacheException("Invalid cache key format");
        }
    }

    private function validateValue($value): void
    {
        if (!is_serializable($value)) {
            throw new CacheException("Cache value must be serializable");
        }
    }

    private function generateCacheKey(string $key): string
    {
        return sprintf(
            '%s:%s:%s',
            $this->config['cache_prefix'] ?? 'app',
            self::CACHE_VERSION,
            $key
        );
    }

    private function calculateIntegrity(string $data): string
    {
        return hash_hmac(
            'sha256',
            $data,
            $this->config['integrity_key']
        );
    }

    private function handleIntegrityFailure(string $key): void
    {
        // Log integrity failure
        $this->auditLogger->logSecurityEvent(
            'cache_integrity_failure',
            ['key' => $key],
            4 // High severity
        );

        // Delete compromised data
        $this->secureDelete($key);
    }

    private function handleCacheFailure(string $operation, \Throwable $e, ?string $key = null): void
    {
        $this->auditLogger->logSecurityEvent(
            'cache_operation_failure',
            [
                'operation' => $operation,
                'key' => $key,
                'error' => $e->getMessage()
            ],
            3 // Medium severity
        );
    }
}
