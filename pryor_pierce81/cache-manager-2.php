<?php

namespace App\Core\Cache;

use Illuminate\Support\Facades\Cache;
use App\Core\Security\SecurityContext;
use App\Core\Contracts\{CacheManagerInterface, ValidatorInterface};
use App\Core\Exceptions\{CacheException, SecurityException};

class CacheManager implements CacheManagerInterface
{
    private SecurityContext $context;
    private ValidatorInterface $validator;
    private AuditLogger $auditLogger;
    private PerformanceMonitor $monitor;
    private array $config;

    public function remember(string $key, $data, int $ttl = 3600): mixed
    {
        $startTime = microtime(true);
        
        try {
            $this->validateKey($key);
            $this->validateSecurity();
            
            $cacheKey = $this->generateSecureKey($key);
            
            $result = Cache::remember($cacheKey, $ttl, function() use ($data) {
                return is_callable($data) ? $data() : $data;
            });
            
            $this->monitor->recordCacheOperation('remember', microtime(true) - $startTime);
            
            return $this->validateResult($result);
            
        } catch (\Throwable $e) {
            $this->handleCacheFailure($e, $key);
            throw $e;
        }
    }

    public function forget(string $key): bool
    {
        try {
            $this->validateKey($key);
            $this->validateSecurity();
            
            $cacheKey = $this->generateSecureKey($key);
            
            $result = Cache::forget($cacheKey);
            
            $this->auditLogger->logCacheOperation('forget', $key);
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->handleCacheFailure($e, $key);
            throw $e;
        }
    }

    public function tags(array $tags): self
    {
        foreach ($tags as $tag) {
            $this->validateTag($tag);
        }
        
        Cache::tags($tags);
        return $this;
    }

    public function invalidatePattern(string $pattern): bool
    {
        try {
            $this->validatePattern($pattern);
            
            $keys = $this->findKeysByPattern($pattern);
            
            foreach ($keys as $key) {
                Cache::forget($key);
            }
            
            $this->auditLogger->logCacheInvalidation($pattern);
            
            return true;
            
        } catch (\Throwable $e) {
            $this->handleCacheFailure($e, $pattern);
            throw $e;
        }
    }

    private function validateKey(string $key): void
    {
        if (strlen($key) > 250) {
            throw new CacheException('Cache key too long');
        }

        if (!preg_match('/^[a-zA-Z0-9_.:-]+$/', $key)) {
            throw new CacheException('Invalid cache key format');
        }
    }

    private function validateTag(string $tag): void
    {
        if (strlen($tag) > 100) {
            throw new CacheException('Cache tag too long');
        }

        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $tag)) {
            throw new CacheException('Invalid cache tag format');
        }
    }

    private function validatePattern(string $pattern): void
    {
        if (strlen($pattern) > 100) {
            throw new CacheException('Cache pattern too long');
        }

        if (!preg_match('/^[a-zA-Z0-9_.*-]+$/', $pattern)) {
            throw new CacheException('Invalid cache pattern format');
        }
    }

    private function validateSecurity(): void
    {
        if (!$this->context->isAuthenticated()) {
            throw new SecurityException('Unauthenticated cache access');
        }

        if ($this->detectAnomalous()) {
            throw new SecurityException('Anomalous cache behavior detected');
        }
    }

    private function validateResult($result): mixed
    {
        if ($result === null) {
            $this->auditLogger->logCacheMiss();
            return null;
        }

        if (!$this->validator->validateCacheData($result)) {
            throw new CacheException('Invalid cache data');
        }

        return $result;
    }

    private function generateSecureKey(string $key): string
    {
        $prefix = $this->context->getUserId() ?? 'anonymous';
        $hash = hash_hmac('sha256', $key, $this->config['secret_key']);
        
        return "{$prefix}:{$hash}";
    }

    private function detectAnomalous(): bool
    {
        $key = 'cache_ops_' . $this->context->getUserId();
        $limit = $this->config['rate_limit'] ?? 1000;
        $window = $this->config['rate_window'] ?? 3600;
        
        $current = Cache::increment($key);
        
        if ($current === 1) {
            Cache::put($key, 1, $window);
        }
        
        return $current > $limit;
    }

    private function findKeysByPattern(string $pattern): array
    {
        $iterator = Cache::getRedis()->scan(0, [
            'match' => $pattern,
            'count' => 1000
        ]);

        return iterator_to_array($iterator);
    }

    private function handleCacheFailure(\Throwable $e, string $key): void
    {
        $this->auditLogger->logCacheFailure([
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'key' => $key,
            'trace' => $e->getTraceAsString()
        ]);
        
        $this->monitor->recordFailure('cache', [
            'operation' => debug_backtrace()[1]['function'],
            'key' => $key,
            'error' => $e->getMessage()
        ]);
    }
}
