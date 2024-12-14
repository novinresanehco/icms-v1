<?php

namespace App\Core\Cache;

use App\Core\Security\SecurityManager;
use App\Core\Protection\CoreProtectionSystem;
use App\Core\Exceptions\{CacheException, PerformanceException};
use Illuminate\Support\Facades\Redis;

class CachePerformanceManager implements CacheManagerInterface
{
    private SecurityManager $security;
    private CoreProtectionSystem $protection;
    private MetricsCollector $metrics;
    private ValidationService $validator;

    public function remember(string $key, $data, SecurityContext $context): mixed
    {
        return $this->protection->executeProtectedOperation(
            function() use ($key, $data, $context) {
                $secureKey = $this->generateSecureKey($key, $context);
                
                if ($cached = $this->getFromCache($secureKey, $context)) {
                    $this->metrics->incrementCacheHit($key);
                    return $cached;
                }

                $value = $this->computeAndCache($secureKey, $data, $context);
                $this->metrics->incrementCacheMiss($key);
                
                return $value;
            },
            $context
        );
    }

    public function store(string $key, $value, array $tags, SecurityContext $context): void
    {
        $this->protection->executeProtectedOperation(
            function() use ($key, $value, $tags, $context) {
                $secureKey = $this->generateSecureKey($key, $context);
                $validatedValue = $this->validateData($value);
                
                $this->storeInCache(
                    $secureKey,
                    $validatedValue,
                    $this->validateTags($tags),
                    $context
                );
            },
            $context
        );
    }

    public function invalidate(array $tags, SecurityContext $context): void
    {
        $this->protection->executeProtectedOperation(
            function() use ($tags, $context) {
                $validTags = $this->validateTags($tags);
                $this->invalidateCacheTags($validTags);
                $this->metrics->recordInvalidation($validTags);
            },
            $context
        );
    }

    public function optimize(SecurityContext $context): PerformanceMetrics
    {
        return $this->protection->executeProtectedOperation(
            function() use ($context) {
                $metrics = $this->analyzePerformance();
                
                if ($metrics->requiresOptimization()) {
                    $this->executeOptimization($metrics);
                }
                
                return $metrics;
            },
            $context
        );
    }

    private function generateSecureKey(string $key, SecurityContext $context): string
    {
        return hash_hmac(
            'sha256',
            $key . $context->getUserId(),
            config('cache.key_salt')
        );
    }

    private function getFromCache(string $key, SecurityContext $context): mixed
    {
        $startTime = microtime(true);
        
        try {
            $value = Redis::get($key);
            
            if ($value) {
                $this->validateCachedData($value);
                $this->auditCacheAccess($key, $context);
            }
            
            return $value ? unserialize($value) : null;
            
        } finally {
            $this->metrics->recordCacheAccess(
                $key,
                microtime(true) - $startTime
            );
        }
    }

    private function computeAndCache(string $key, $data, SecurityContext $context): mixed
    {
        $startTime = microtime(true);
        
        try {
            $value = is_callable($data) ? $data() : $data;
            $validatedValue = $this->validateData($value);
            
            $this->storeInCache(
                $key,
                $validatedValue,
                [],
                $context
            );
            
            return $value;
            
        } finally {
            $this->metrics->recordComputation(
                $key,
                microtime(true) - $startTime
            );
        }
    }

    private function storeInCache(string $key, $value, array $tags, SecurityContext $context): void
    {
        $serialized = serialize($value);
        
        Redis::pipeline(function($pipe) use ($key, $serialized, $tags) {
            $pipe->set($key, $serialized);
            $pipe->expire($key, config('cache.ttl'));
            
            foreach ($tags as $tag) {
                $pipe->sadd("tag:$tag", $key);
            }
        });

        $this->auditCacheStore($key, $tags, $context);
    }

    private function validateData($data): mixed
    {
        if (!$this->validator->validateCacheData($data)) {
            throw new CacheException('Invalid cache data');
        }
        return $data;
    }

    private function validateTags(array $tags): array
    {
        return array_filter($tags, function($tag) {
            return $this->validator->validateCacheTag($tag);
        });
    }

    private function validateCachedData($data): void
    {
        if (!$this->validator->validateCachedData($data)) {
            throw new CacheException('Cached data validation failed');
        }
    }

    private function invalidateCacheTags(array $tags): void
    {
        Redis::pipeline(function($pipe) use ($tags) {
            foreach ($tags as $tag) {
                $keys = Redis::smembers("tag:$tag");
                if (!empty($keys)) {
                    $pipe->del($keys);
                    $pipe->del("tag:$tag");
                }
            }
        });
    }

    private function analyzePerformance(): PerformanceMetrics
    {
        return new PerformanceMetrics([
            'hit_rate' => $this->metrics->getCacheHitRate(),
            'miss_rate' => $this->metrics->getCacheMissRate(),
            'average_access_time' => $this->metrics->getAverageAccessTime(),
            'memory_usage' => $this->metrics->getCacheMemoryUsage(),
            'fragmentation' => $this->metrics->getCacheFragmentation()
        ]);
    }

    private function executeOptimization(PerformanceMetrics $metrics): void
    {
        if ($metrics->getFragmentation() > 50) {
            $this->defragmentCache();
        }

        if ($metrics->getMemoryUsage() > 80) {
            $this->evictLeastUsed();
        }

        if ($metrics->getAverageAccessTime() > 100) {
            $this->optimizeKeyDistribution();
        }
    }
}
