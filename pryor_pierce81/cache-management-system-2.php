<?php

namespace App\Core\Cache;

class CacheManager implements CacheManagerInterface
{
    private SecurityManager $security;
    private CacheStore $store;
    private array $defaultConfig;
    private MetricsCollector $metrics;

    public function __construct(
        SecurityManager $security,
        CacheStore $store,
        MetricsCollector $metrics,
        array $defaultConfig = []
    ) {
        $this->security = $security;
        $this->store = $store;
        $this->metrics = $metrics;
        $this->defaultConfig = array_merge([
            'ttl' => 3600,
            'tags' => [],
            'encrypt' => false
        ], $defaultConfig);
    }

    public function remember(string $key, $value, ?array $config = null): mixed
    {
        $config = $this->mergeConfig($config);
        $cacheKey = $this->generateCacheKey($key);

        try {
            $this->validateCacheOperation('remember', $cacheKey);
            
            if ($this->has($cacheKey)) {
                $this->metrics->incrementCacheHit($key);
                return $this->get($cacheKey);
            }

            $result = value($value);
            $this->set($cacheKey, $result, $config);
            
            $this->metrics->incrementCacheMiss($key);
            return $result;

        } catch (\Exception $e) {
            $this->handleCacheException($e, 'remember', $cacheKey);
            return value($value);
        }
    }

    public function get(string $key): mixed
    {
        try {
            $this->validateCacheOperation('get', $key);
            
            $value = $this->store->get($key);
            
            if ($value === null) {
                $this->metrics->incrementCacheMiss($key);
                return null;
            }

            $this->metrics->incrementCacheHit($key);
            return $this->decryptIfNeeded($value);

        } catch (\Exception $e) {
            $this->handleCacheException($e, 'get', $key);
            return null;
        }
    }

    public function set(string $key, $value, ?array $config = null): bool
    {
        $config = $this->mergeConfig($config);

        try {
            $this->validateCacheOperation('set', $key);
            
            if ($config['encrypt']) {
                $value = $this->encrypt($value);
            }

            $stored = $this->store->put(
                $key,
                $value,
                $config['ttl']
            );

            if ($stored && !empty($config['tags'])) {
                $this->tagCache($key, $config['tags']);
            }

            return $stored;

        } catch (\Exception $e) {
            $this->handleCacheException($e, 'set', $key);
            return false;
        }
    }

    public function forget(string $key): bool
    {
        try {
            $this->validateCacheOperation('forget', $key);
            return $this->store->forget($key);
        } catch (\Exception $e) {
            $this->handleCacheException($e, 'forget', $key);
            return false;
        }
    }

    public function tags(array $tags): self
    {
        return new static(
            $this->security,
            $this->store->tags($tags),
            $this->metrics,
            $this->defaultConfig
        );
    }

    public function flush(?array $tags = null): bool
    {
        try {
            $this->validateCacheOperation('flush', 'all');
            
            if ($tags) {
                return $this->store->tags($tags)->flush();
            }
            
            return $this->store->flush();

        } catch (\Exception $e) {
            $this->handleCacheException($e, 'flush', 'all');
            return false;
        }
    }

    private function validateCacheOperation(string $operation, string $key): void
    {
        $this->security->validateCriticalOperation([
            'action' => "cache.{$operation}",
            'key' => $key
        ]);
    }

    private function generateCacheKey(string $key): string
    {
        return hash('sha256', $key);
    }

    private function encrypt($value): string
    {
        return $this->security->encrypt(serialize($value));
    }

    private function decrypt(string $value): mixed
    {
        return unserialize($this->security->decrypt($value));
    }

    private function decryptIfNeeded($value): mixed
    {
        return is_string($value) && $this->isEncrypted($value) 
            ? $this->decrypt($value)
            : $value;
    }

    private function isEncrypted(string $value): bool
    {
        return Str::startsWith($value, 'eyJ');
    }

    private function tagCache(string $key, array $tags): void
    {
        foreach ($tags as $tag) {
            $this->store->tags([$tag])->put(
                "{$tag}:keys",
                array_merge(
                    $this->store->get("{$tag}:keys", []),
                    [$key]
                ),
                $this->defaultConfig['ttl']
            );
        }
    }

    private function mergeConfig(?array $config): array
    {
        return array_merge($this->defaultConfig, $config ?? []);
    }

    private function handleCacheException(\Exception $e, string $operation, string $key): void
    {
        $this->metrics->incrementCacheError($operation);
        
        Log::error('Cache operation failed', [
            'operation' => $operation,
            'key' => $key,
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
