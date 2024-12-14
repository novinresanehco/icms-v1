<?php

namespace App\Core\Cache;

class CacheManager implements CacheManagerInterface 
{
    private CacheStore $store;
    private ValidationService $validator;
    private SecurityManager $security;
    private MetricsCollector $metrics;
    private array $config;

    public function __construct(
        CacheStore $store,
        ValidationService $validator,
        SecurityManager $security,
        MetricsCollector $metrics,
        array $config
    ) {
        $this->store = $store;
        $this->validator = $validator;
        $this->security = $security;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $startTime = microtime(true);
        $cacheKey = $this->generateSecureKey($key);
        
        try {
            if ($cached = $this->getFromCache($cacheKey)) {
                $this->recordMetrics('hit', $startTime);
                return $cached;
            }

            $value = $callback();
            $this->validateValue($value);
            
            $this->storeInCache($cacheKey, $value, $ttl);
            $this->recordMetrics('miss', $startTime);
            
            return $value;
        } catch (\Exception $e) {
            $this->handleCacheFailure($e, $key);
            throw $e;
        }
    }

    public function rememberForever(string $key, callable $callback): mixed 
    {
        return $this->remember($key, $callback, null);
    }

    public function forget(string $key): bool 
    {
        $cacheKey = $this->generateSecureKey($key);
        
        try {
            $result = $this->store->delete($cacheKey);
            $this->recordDeletion($key);
            return $result;
        } catch (\Exception $e) {
            $this->handleCacheFailure($e, $key);
            throw $e;
        }
    }

    public function flush(): bool 
    {
        try {
            $result = $this->store->flush();
            $this->recordFlush();
            return $result;
        } catch (\Exception $e) {
            $this->handleCacheFailure($e, 'flush');
            throw $e;
        }
    }

    private function generateSecureKey(string $key): string 
    {
        return hash_hmac(
            'sha256',
            $key,
            $this->config['cache_key_salt']
        );
    }

    private function getFromCache(string $key): mixed 
    {
        $value = $this->store->get($key);
        
        if ($value !== null) {
            $this->validateValue($value);
            $this->verifyIntegrity($key, $value);
        }
        
        return $value;
    }

    private function storeInCache(string $key, mixed $value, ?int $ttl): void 
    {
        $ttl = $ttl ?? $this->config['default_ttl'];
        
        if (!$this->store->set($key, $value, $ttl)) {
            throw new CacheException("Failed to store value in cache");
        }
    }

    private function validateValue(mixed $value): void 
    {
        if (!$this->validator->isValid($value)) {
            throw new ValidationException("Invalid cache value");
        }
    }

    private function verifyIntegrity(string $key, mixed $value): void 
    {
        if (!$this->security->verifyIntegrity($key, $value)) {
            throw new SecurityException("Cache integrity check failed");
        }
    }

    private function handleCacheFailure(\Exception $e, string $key): void 
    {
        $this->metrics->incrementErrorCount('cache_failure', [
            'key' => $key,
            'error' => $e->getMessage()
        ]);
    }

    private function recordMetrics(string $type, float $startTime): void 
    {
        $duration = microtime(true) - $startTime;
        
        $this->metrics->record('cache_operation', [
            'type' => $type,
            'duration' => $duration,
            'memory' => memory_get_peak_usage(true)
        ]);
    }

    private function recordDeletion(string $key): void 
    {
        $this->metrics->increment('cache_deletion', [
            'key' => $key
        ]);
    }

    private function recordFlush(): void 
    {
        $this->metrics->increment('cache_flush');
    }
}
