<?php

namespace App\Core\Cache;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Validation\ValidationManagerInterface;
use App\Core\Exception\CacheException;
use Psr\Log\LoggerInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;

/**
 * Critical Cache Management System
 * SECURITY LEVEL: CRITICAL
 * ERROR TOLERANCE: ZERO
 */
class CacheManager implements CacheManagerInterface
{
    private SecurityManagerInterface $security;
    private ValidationManagerInterface $validator;
    private LoggerInterface $logger;
    private Repository $cache;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        ValidationManagerInterface $validator,
        LoggerInterface $logger,
        Repository $cache,
        array $config = []
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->logger = $logger;
        $this->cache = $cache;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Store data in cache with comprehensive security validation
     *
     * @throws CacheException If any security or validation check fails
     */
    public function store(string $key, mixed $value, ?int $ttl = null): bool
    {
        $operationId = $this->generateOperationId();
        
        try {
            DB::beginTransaction();

            // Security validation
            $this->security->validateSecureOperation('cache:store', [
                'key' => $key,
                'operation_id' => $operationId
            ]);

            // Input validation
            $this->validateCacheKey($key);
            $this->validateCacheValue($value);
            $this->validateTtl($ttl);

            // Pre-store data processing
            $processedValue = $this->prepareValueForCache($value);
            $finalTtl = $this->calculateTtl($ttl);

            // Execute store operation
            $success = $this->executeStore($key, $processedValue, $finalTtl);

            // Post-store validation
            $this->verifyCacheOperation($key, $processedValue);

            $this->logCacheOperation($operationId, 'store', $key);
            
            DB::commit();
            
            return $success;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleCacheFailure($operationId, 'store', $key, $e);
            throw new CacheException('Cache store operation failed', 0, $e);
        }
    }

    /**
     * Retrieve data from cache with integrity verification
     *
     * @throws CacheException If retrieval or verification fails
     */
    public function get(string $key): mixed
    {
        $operationId = $this->generateOperationId();

        try {
            // Security validation
            $this->security->validateSecureOperation('cache:retrieve', [
                'key' => $key,
                'operation_id' => $operationId
            ]);

            $this->validateCacheKey($key);

            // Execute retrieval with verification
            $value = $this->executeRetrieve($key);
            
            if ($value !== null) {
                $this->verifyDataIntegrity($value);
                $value = $this->processRetrievedValue($value);
            }

            $this->logCacheOperation($operationId, 'retrieve', $key);

            return $value;

        } catch (\Exception $e) {
            $this->handleCacheFailure($operationId, 'retrieve', $key, $e);
            throw new CacheException('Cache retrieve operation failed', 0, $e);
        }
    }

    /**
     * Remove data from cache with security validation
     *
     * @throws CacheException If removal operation fails
     */
    public function remove(string $key): bool
    {
        $operationId = $this->generateOperationId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('cache:remove', [
                'key' => $key,
                'operation_id' => $operationId
            ]);

            $this->validateCacheKey($key);

            $success = $this->executeRemove($key);
            $this->verifyCacheRemoval($key);

            $this->logCacheOperation($operationId, 'remove', $key);

            DB::commit();

            return $success;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleCacheFailure($operationId, 'remove', $key, $e);
            throw new CacheException('Cache remove operation failed', 0, $e);
        }
    }

    /**
     * Clear all cache with security verification
     *
     * @throws CacheException If clear operation fails
     */
    public function clear(): bool
    {
        $operationId = $this->generateOperationId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('cache:clear', [
                'operation_id' => $operationId
            ]);

            $success = $this->executeClear();
            $this->verifyCacheClear();

            $this->logCacheOperation($operationId, 'clear', '*');

            DB::commit();

            return $success;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleCacheFailure($operationId, 'clear', '*', $e);
            throw new CacheException('Cache clear operation failed', 0, $e);
        }
    }

    private function validateCacheKey(string $key): void
    {
        if (!preg_match($this->config['key_pattern'], $key)) {
            throw new CacheException('Invalid cache key format');
        }

        if (strlen($key) > $this->config['max_key_length']) {
            throw new CacheException('Cache key exceeds maximum length');
        }
    }

    private function validateCacheValue(mixed $value): void
    {
        if (!$this->validator->validateCacheValue($value)) {
            throw new CacheException('Invalid cache value');
        }
    }

    private function validateTtl(?int $ttl): void
    {
        if ($ttl !== null) {
            if ($ttl < $this->config['min_ttl'] || $ttl > $this->config['max_ttl']) {
                throw new CacheException('Invalid TTL value');
            }
        }
    }

    private function prepareValueForCache(mixed $value): mixed
    {
        // Add integrity hash and metadata
        return [
            'data' => $value,
            'timestamp' => time(),
            'hash' => $this->generateValueHash($value),
            'metadata' => $this->generateValueMetadata($value)
        ];
    }

    private function processRetrievedValue(mixed $value): mixed
    {
        if (!is_array($value) || !isset($value['data'], $value['hash'])) {
            throw new CacheException('Invalid cache value structure');
        }

        if (!$this->verifyValueHash($value['data'], $value['hash'])) {
            throw new CacheException('Cache value integrity check failed');
        }

        return $value['data'];
    }

    private function getDefaultConfig(): array
    {
        return [
            'key_pattern' => '/^[a-zA-Z0-9:._-]+$/',
            'max_key_length' => 250,
            'min_ttl' => 60,
            'max_ttl' => 86400 * 30,
            'default_ttl' => 3600,
            'value_max_size' => 1024 * 1024,
            'encryption_enabled' => true,
            'compression_enabled' => true
        ];
    }

    private function generateOperationId(): string
    {
        return uniqid('cache_', true);
    }

    private function generateValueHash(mixed $value): string
    {
        return hash_hmac('sha256', serialize($value), $this->config['hash_key']);
    }

    private function verifyValueHash(mixed $value, string $hash): bool
    {
        return hash_equals($hash, $this->generateValueHash($value));
    }

    private function handleCacheFailure(string $operationId, string $operation, string $key, \Exception $e): void
    {
        $this->logger->error('Cache operation failed', [
            'operation_id' => $operationId,
            'operation' => $operation,
            'key' => $key,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->notifyCacheFailure($operationId, $operation, $key, $e);
    }
}
