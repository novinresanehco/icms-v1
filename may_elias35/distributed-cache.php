<?php

namespace App\Core\Cache;

class DistributedCacheManager
{
    private CacheStore $primaryCache;
    private CacheStore $backupCache;
    private LockManager $lockManager;
    private CacheMetrics $metrics;

    public function __construct(
        CacheStore $primaryCache,
        CacheStore $backupCache,
        LockManager $lockManager,
        CacheMetrics $metrics
    ) {
        $this->primaryCache = $primaryCache;
        $this->backupCache = $backupCache;
        $this->lockManager = $lockManager;
        $this->metrics = $metrics;
    }

    public function get(string $key, array $tags = []): mixed
    {
        $startTime = microtime(true);

        try {
            // Try primary cache first
            $value = $this->primaryCache->get($key);
            
            if ($value !== null) {
                $this->metrics->recordHit('primary', microtime(true) - $startTime);
                return $value;
            }

            // Try backup cache
            $value = $this->backupCache->get($key);
            
            if ($value !== null) {
                // Restore to primary cache
                $this->primaryCache->set($key, $value, $tags);
                $this->metrics->recordHit('backup', microtime(true) - $startTime);
                return $value;
            }

            $this->metrics->recordMiss(microtime(true) - $startTime);
            return null;

        } catch (CacheException $e) {
            $this->metrics->recordError($e);
            throw $e;
        }
    }

    public function set(string $key, mixed $value, array $tags = [], ?int $ttl = null): bool
    {
        $lock = $this->lockManager->acquire("cache:$key");

        try {
            $success = $this->primaryCache->set($key, $value, $tags, $ttl);
            
            if ($success) {
                $this->backupCache->set($key, $value, $tags, $ttl);
            }

            return $success;

        } finally {
            $lock->release();
        }
    }

    public function invalidate(array $tags): void
    {
        $startTime = microtime(true);

        try {
            $this->primaryCache->invalidate($tags);
            $this->backupCache->invalidate($tags);
            
            $this->metrics->recordInvalidation($tags, microtime(true) - $startTime);

        } catch (CacheException $e) {
            $this->metrics->recordError($e);
            throw $e;
        }
    }

    public function invalidateByPattern(string $pattern): void
    {
        $startTime = microtime(true);

        try {
            $keys = $this->primaryCache->getKeysByPattern($pattern);
            
            foreach ($keys as $key) {
                $this->invalidateKey($key);
            }

            $this->metrics->recordPatternInvalidation(
                $pattern,
                count($keys),
                microtime(true) - $startTime
            );

        } catch (CacheException $e) {
            $this->metrics->recordError($e);
            throw $e;
        }
    }

    protected function invalidateKey(string $key): void
    {
        $lock = $this->lockManager->acquire("cache:$key:invalidate");

        try {
            $this->primaryCache->delete($key);
            $this->backupCache->delete($key);
        } finally {
            $lock->release();
        }
    }

    public function getMetrics(): array
    {
        return [
            'hits' => [
                'primary' => $this->metrics->getHitCount('primary'),
                'backup' => $this->metrics->getHitCount('backup')
            ],
            'misses' => $this->metrics->getMissCount(),
            'invalidations' => $this->metrics->getInvalidationCount(),
            'errors' => $this->metrics->getErrorCount(),
            'hit_rate' => $this->metrics->getHitRate(),
            'average_latency' => $this->metrics->getAverageLatency()
        ];
    }
}
