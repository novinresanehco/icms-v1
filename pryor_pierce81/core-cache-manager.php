<?php

namespace App\Core\Cache;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\CacheException;
use Psr\Log\LoggerInterface;

class CacheManager implements CacheManagerInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $stores = [];
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $operationId = $this->generateOperationId();

        try {
            $this->security->validateContext('cache:read');
            $this->validateKey($key);

            $value = $this->retrieveFromStore($key);
            
            if ($value === null) {
                return $default;
            }

            $this->logCacheHit($operationId, $key);
            return $this->unserialize($value);

        } catch (\Exception $e) {
            $this->handleCacheFailure($operationId, 'get', $key, $e);
            throw new CacheException("Cache get failed: {$key}", 0, $e);
        }
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $operationId = $this->generateOperationId();

        try {
            $this->security->validateContext('cache:write');
            $this->validateKey($key);
            $this->validateValue($value);

            $ttl = $ttl ?? $this->config['default_ttl'];
            $serialized = $this->serialize($value);

            $success = $this->storeInCache($key, $serialized, $ttl);
            
            if ($success) {
                $this->logCacheSet($operationId, $key, $ttl);
            }

            return $success;

        } catch (\Exception $e) {
            $this->handleCacheFailure($operationId, 'set', $key, $e);
            throw new CacheException("Cache set failed: {$key}", 0, $e);
        }
    }

    public function delete(string $key): bool
    {
        $operationId = $this->generateOperationId();

        try {
            $this->security->validateContext('cache:delete');
            $this->validateKey($key);

            $success = $this->removeFromStore($key);
            
            if ($success) {
                $this->logCacheDelete($operationId, $key);
            }

            return $success;

        } catch (\Exception $e) {
            $this->handleCacheFailure($operationId, 'delete', $key, $e);
            throw new CacheException("Cache delete failed: {$key}", 0, $e);
        }
    }

    public function clear(): bool
    {
        $operationId = $this->generateOperationId();

        try {
            $this->security->validateContext('cache:clear');

            $success = $this->clearAllStores();
            
            if ($success) {
                $this->logCacheClear($operationId);
            }

            return $success;

        } catch (\Exception $e) {
            $this->handleCacheFailure($operationId, 'clear', null, $e);
            throw new CacheException('Cache clear failed', 0, $e);
        }
    }

    private function validateKey(string $key): void
    {
        if (strlen($key) > $this->config['max_key_length']) {
            throw new CacheException('Cache key exceeds maximum length');
        }

        if (!preg_match('/^[a-zA-Z0-9:._-]+$/', $key)) {
            throw new CacheException('Invalid cache key format');
        }
    }

    private function validateValue(mixed $value): void
    {
        $serialized = $this->serialize($value);
        
        if (strlen($serialized) > $this->config['max_value_size']) {
            throw new CacheException('Cache value exceeds maximum size');
        }
    }

    private function serialize(mixed $value): string
    {
        try {
            return serialize($value);
        } catch (\Exception $e) {
            throw new CacheException('Failed to serialize cache value', 0, $e);
        }
    }

    private function unserialize(string $value): mixed
    {
        try {
            return unserialize($value);
        } catch (\Exception $e) {
            throw new CacheException('Failed to unserialize cache value', 0, $e);
        }
    }

    private function retrieveFromStore(string $key): ?string
    {
        foreach ($this->stores as $store) {
            if ($value = $store->get($key)) {
                return $value;
            }
        }
        return null;
    }

    private function storeInCache(string $key, string $value, int $ttl): bool
    {
        $success = true;
        foreach ($this->stores as $store) {
            if (!$store->set($key, $value, $ttl)) {
                $success = false;
            }
        }
        return $success;
    }

    private function removeFromStore(string $key): bool
    {
        $success = true;
        foreach ($this->stores as $store) {
            if (!$store->delete($key)) {
                $success = false;
            }
        }
        return $success;
    }

    private function clearAllStores(): bool
    {
        $success = true;
        foreach ($this->stores as $store) {
            if (!$store->clear()) {
                $success = false;
            }
        }
        return $success;
    }

    private function generateOperationId(): string
    {
        return uniqid('cache_', true);
    }

    private function logCacheHit(string $operationId, string $key): void
    {
        $this->logger->info('Cache hit', [
            'operation_id' => $operationId,
            'key' => $key,
            'timestamp' => microtime(true)
        ]);
    }

    private function logCacheSet(string $operationId, string $key, int $ttl): void
    {
        $this->logger->info('Cache set', [
            'operation_id' => $operationId,
            'key' => $key,
            'ttl' => $ttl,
            'timestamp' => microtime(true)
        ]);
    }

    private function logCacheDelete(string $operationId, string $key): void
    {
        $this->logger->info('Cache delete', [
            'operation_id' => $operationId,
            'key' => $key,
            'timestamp' => microtime(true)
        ]);
    }

    private function logCacheClear(string $operationId): void
    {
        $this->logger->info('Cache clear', [
            'operation_id' => $operationId,
            'timestamp' => microtime(true)
        ]);
    }

    private function handleCacheFailure(
        string $operationId,
        string $operation,
        ?string $key,
        \Exception $e
    ): void {
        $this->logger->error('Cache operation failed', [
            'operation_id' => $operationId,
            'operation' => $operation,
            'key' => $key,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function getDefaultConfig(): array
    {
        return [
            'default_ttl' => 3600,
            'max_key_length' => 255,
            'max_value_size' => 1048576,
            'prefix' => 'app:',
            'fallback_enabled' => true
        ];
    }
}
