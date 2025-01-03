<?php

namespace App\Core\Infrastructure;

class CacheManager implements CacheManagerInterface
{
    private StorageManager $storage;
    private SecurityManager $security;
    private DatabaseManager $database;
    private MetricsCollector $metrics;
    private AuditService $audit;
    private array $stores = [];
    private array $config;

    public function __construct(
        StorageManager $storage,
        SecurityManager $security, 
        DatabaseManager $database,
        MetricsCollector $metrics,
        AuditService $audit,
        array $config
    ) {
        $this->storage = $storage;
        $this->security = $security;
        $this->database = $database;
        $this->metrics = $metrics;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function get(string $key, string $store = 'default'): mixed
    {
        $startTime = microtime(true);
        
        try {
            $this->validateStore($store);
            $this->validateKey($key);
            
            $cacheStore = $this->getStore($store);
            $value = $cacheStore->get($this->secureKey($key));
            
            if ($value !== null) {
                $value = $this->decryptIfNeeded($value, $store);
                $this->metrics->recordCacheHit($store, microtime(true) - $startTime);
            } else {
                $this->metrics->recordCacheMiss($store, microtime(true) - $startTime);
            }
            
            return $value;
            
        } catch (\Exception $e) {
            $this->handleCacheFailure($e, 'get', $key);
            return null;
        }
    }

    public function put(string $key, mixed $value, int $ttl = null, string $store = 'default'): bool
    {
        try {
            $this->validateStore($store);
            $this->validateKey($key);
            $this->validateValue($value);
            
            $securedValue = $this->encryptIfNeeded($value, $store);
            $cacheStore = $this->getStore($store);
            
            $success = $cacheStore->put(
                $this->secureKey($key),
                $securedValue,
                $ttl ?? $this->getDefaultTtl($store)
            );
            
            if ($success) {
                $this->audit->logCacheOperation('put', $key, $store);
            }
            
            return $success;
            
        } catch (\Exception $e) {
            $this->handleCacheFailure($e, 'put', $key);
            return false;
        }
    }

    public function remember(string $key, callable $callback, int $ttl = null, string $store = 'default'): mixed
    {
        $value = $this->get($key, $store);
        
        if ($value !== null) {
            return $value;
        }
        
        try {
            $value = $callback();
            $this->put($key, $value, $ttl, $store);
            return $value;
            
        } catch (\Exception $e) {
            $this->handleCacheFailure($e, 'remember', $key);
            return $callback();
        }
    }

    public function forget(string $key, string $store = 'default'): bool
    {
        try {
            $this->validateStore($store);
            $this->validateKey($key);
            
            $cacheStore = $this->getStore($store);
            $success = $cacheStore->forget($this->secureKey($key));
            
            if ($success) {
                $this->audit->logCacheOperation('forget', $key, $store);
            }
            
            return $success;
            
        } catch (\Exception $e) {
            $this->handleCacheFailure($e, 'forget', $key);
            return false;
        }
    }

    public function flush(string $store = 'default'): bool
    {
        try {
            $this->validateStore($store);
            
            $cacheStore = $this->getStore($store);
            $success = $cacheStore->flush();
            
            if ($success) {
                $this->audit->logCacheOperation('flush', null, $store);
            }
            
            return $success;
            
        } catch (\Exception $e) {
            $this->handleCacheFailure($e, 'flush', null);
            return false;
        }
    }

    public function tags(array $tags): TaggedCache
    {
        return new TaggedCache($this, $tags);
    }

    public function getMemoryUsage(string $store = 'default'): array
    {
        try {
            $this->validateStore($store);
            $cacheStore = $this->getStore($store);
            
            return [
                'used' => $cacheStore->getUsedMemory(),
                'available' => $cacheStore->getAvailableMemory(),
                'max' => $cacheStore->getMaxMemory()
            ];
            
        } catch (\Exception $e) {
            $this->handleCacheFailure($e, 'getMemoryUsage', null);
            return [];
        }
    }

    public function optimizeStore(string $store = 'default'): bool
    {
        try {
            $this->validateStore($store);
            $cacheStore = $this->getStore($store);
            
            $success = $cacheStore->optimize();
            
            if ($success) {
                $this->audit->logCacheOperation('optimize', null, $store);
            }
            
            return $success;
            
        } catch (\Exception $e) {
            $this->handleCacheFailure($e, 'optimize', null);
            return false;
        }
    }

    public function monitorPerformance(): array
    {
        $metrics = [];
        
        foreach ($this->stores as $store => $cacheStore) {
            $metrics[$store] = [
                'memory' => $this->getMemoryUsage($store),
                'hits' => $this->metrics->getCacheHits($store),
                'misses' => $this->metrics->getCacheMisses($store),
                'size' => $cacheStore->getSize(),
                'keys' => $cacheStore->getKeyCount(),
                'ttl' => $this->getDefaultTtl($store)
            ];
        }
        
        return $metrics;
    }

    private function getStore(string $store): CacheStore
    {
        if (!isset($this->stores[$store])) {
            $this->stores[$store] = $this->createStore($store);
        }
        
        return $this->stores[$store];
    }

    private function createStore(string $store): CacheStore
    {
        $config = $this->getStoreConfig($store);
        return new CacheStore($config, $this->storage);
    }

    private function validateStore(string $store): void
    {
        if (!isset($this->config['stores'][$store])) {
            throw new InvalidStoreException("Invalid cache store: {$store}");
        }
    }

    private function validateKey(string $key): void
    {
        if (empty($key)) {
            throw new InvalidKeyException('Cache key cannot be empty');
        }
    }

    private function validateValue(mixed $value): void
    {
        if (!is_serializable($value)) {
            throw new InvalidValueException('Cache value must be serializable');
        }
    }

    private function secureKey(string $key): string
    {
        return hash_hmac('sha256', $key, $this->config['key_salt']);
    }

    private function encryptIfNeeded(mixed $value, string $store): string
    {
        if ($this->shouldEncrypt($store)) {
            return $this->security->encrypt(serialize($value));
        }
        
        return serialize($value);
    }

    private function decryptIfNeeded(string $value, string $store): mixed
    {
        if ($this->shouldEncrypt($store)) {
            return unserialize($this->security->decrypt($value));
        }
        
        return unserialize($value);
    }

    private function shouldEncrypt(string $store): bool
    {
        return $this->getStoreConfig($store)['encrypt'] ?? false;
    }

    private function getStoreConfig(string $store): array
    {
        return $this->config['stores'][$store];
    }

    private function getDefaultTtl(string $store): int
    {
        return $this->getStoreConfig($store)['ttl'] ?? 3600;
    }

    private function handleCacheFailure(\Exception $e, string $operation, ?string $key): void
    {
        $this->audit->logCacheFailure($operation, $key, $e);
        $this->metrics->recordCacheFailure($operation);
    }
}
