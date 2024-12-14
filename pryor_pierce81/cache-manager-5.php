<?php

namespace App\Core\Cache;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\CacheException;
use Psr\Log\LoggerInterface;

class CacheManager implements CacheManagerInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $config;
    private array $handlers = [];

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function store(string $key, $data, int $ttl = null): bool
    {
        $operationId = $this->generateOperationId();

        try {
            $this->security->validateSecureOperation('cache:store', ['key' => $key]);
            $this->validateKey($key);
            $this->validateData($data);

            $ttl = $ttl ?? $this->config['default_ttl'];
            $this->validateTTL($ttl);

            $encrypted = $this->encryptData($data);
            $stored = $this->storeData($key, $encrypted, $ttl);

            $this->logCacheOperation($operationId, 'store', $key);

            return $stored;

        } catch (\Exception $e) {
            $this->handleCacheFailure($operationId, 'store', $e);
            throw new CacheException('Cache store operation failed', 0, $e);
        }
    }

    public function retrieve(string $key)
    {
        $operationId = $this->generateOperationId();

        try {
            $this->security->validateSecureOperation('cache:retrieve', ['key' => $key]);
            $this->validateKey($key);

            $encrypted = $this->fetchData($key);
            if ($encrypted === null) {
                return null;
            }

            $data = $this->decryptData($encrypted);
            $this->validateRetrievedData($data);

            $this->logCacheOperation($operationId, 'retrieve', $key);

            return $data;

        } catch (\Exception $e) {
            $this->handleCacheFailure($operationId, 'retrieve', $e);
            throw new CacheException('Cache retrieve operation failed', 0, $e);
        }
    }

    public function invalidate(string $key): bool
    {
        $operationId = $this->generateOperationId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('cache:invalidate', ['key' => $key]);
            $this->validateKey($key);

            $success = $this->removeData($key);
            $this->logCacheOperation($operationId, 'invalidate', $key);

            DB::commit();
            return $success;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleCacheFailure($operationId, 'invalidate', $e);
            throw new CacheException('Cache invalidation failed', 0, $e);
        }
    }

    private function validateKey(string $key): void
    {
        if (strlen($key) > $this->config['max_key_length']) {
            throw new CacheException('Cache key exceeds maximum length');
        }

        if (!preg_match($this->config['key_pattern'], $key)) {
            throw new CacheException('Invalid cache key format');
        }
    }

    private function validateData($data): void
    {
        $serialized = serialize($data);

        if (strlen($serialized) > $this->config['max_value_size']) {
            throw new CacheException('Cache value exceeds maximum size');
        }
    }

    private function validateTTL(int $ttl): void
    {
        if ($ttl < 0) {
            throw new CacheException('TTL cannot be negative');
        }

        if ($ttl > $this->config['max_ttl']) {
            throw new CacheException('TTL exceeds maximum allowed value');
        }
    }

    private function encryptData($data): string
    {
        return $this->security->encryptData(serialize($data));
    }

    private function decryptData(string $encrypted)
    {
        return unserialize($this->security->decryptData($encrypted));
    }

    private function handleCacheFailure(string $id, string $operation, \Exception $e): void
    {
        $this->logger->error('Cache operation failed', [
            'operation_id' => $id,
            'operation' => $operation,
            'error' => $e->getMessage()
        ]);
    }

    private function getDefaultConfig(): array
    {
        return [
            'default_ttl' => 3600,
            'max_ttl' => 86400,
            'max_key_length' => 255,
            'max_value_size' => 1048576,
            'key_pattern' => '/^[a-zA-Z0-9:_-]+$/',
            'encryption_enabled' => true
        ];
    }
}
