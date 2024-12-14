<?php

namespace App\Core\Performance;

class PerformanceManager implements PerformanceInterface
{
    private CacheManager $cache;
    private SecurityManager $security;
    private MetricsCollector $metrics;

    public function executeOptimized(Operation $operation): Result
    {
        $cacheKey = $this->generateCacheKey($operation);
        
        if ($cached = $this->getSecureCache($cacheKey, $operation)) {
            $this->metrics->recordCacheHit($operation);
            return $cached;
        }

        return DB::transaction(function() use ($operation, $cacheKey) {
            $this->metrics->startOperation($operation);
            
            $result = $this->security->enforceOperation(
                fn() => $this->executeAndCache($operation, $cacheKey)
            );
            
            $this->metrics->endOperation($operation);
            
            return $result;
        });
    }

    private function executeAndCache(Operation $operation, string $key): Result
    {
        $result = $operation->execute();
        
        $this->cache->remember($key, $result, [
            'tags' => $this->getCacheTags($operation),
            'ttl' => $this->getCacheTTL($operation)
        ]);
        
        $this->metrics->recordCacheMiss($operation);
        
        return $result;
    }

    private function getSecureCache(string $key, Operation $operation): ?Result
    {
        $cached = $this->cache->get($key);
        
        if (!$cached) return null;
        
        if (!$this->security->validateCachedData($cached, $operation)) {
            $this->cache->forget($key);
            return null;
        }
        
        return $cached;
    }

    private function generateCacheKey(Operation $operation): string
    {
        return hash_hmac(
            'sha256',
            serialize($operation->getCacheKeyData()),
            config('app.key')
        );
    }
}