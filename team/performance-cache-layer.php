<?php

namespace App\Core\Performance;

use Illuminate\Support\Facades\Cache;
use App\Core\Security\SecurityManager;
use App\Core\Exceptions\{CacheException, PerformanceException};
use Psr\SimpleCache\InvalidArgumentException;

class PerformanceManager implements PerformanceInterface
{
    private SecurityManager $security;
    private CacheRepository $cacheRepo;
    private MetricsCollector $metrics;
    private PerformanceConfig $config;
    
    private const CACHE_PREFIX = 'cms_perf:';
    private const METRICS_PREFIX = 'perf_metrics:';

    public function withCaching(string $key, callable $operation, array $tags = [], int $ttl = 3600): mixed
    {
        $cacheKey = self::CACHE_PREFIX . $key;
        
        try {
            $startTime = microtime(true);
            
            if ($this->config->isCacheEnabled() && Cache::tags($tags)->has($cacheKey)) {
                $result = Cache::tags($tags)->get($cacheKey);
                $this->metrics->recordCacheHit($key, microtime(true) - $startTime);
                return $result;
            }

            $result = $this->executeWithProfiling($operation, $key);
            
            if ($this->config->isCacheEnabled()) {
                Cache::tags($tags)->put($cacheKey, $result, $ttl);
            }
            
            $this->metrics->recordCacheMiss($key, microtime(true) - $startTime);
            return $result;
            
        } catch (InvalidArgumentException $e) {
            $this->metrics->recordCacheError($key, $e);
            throw new CacheException('Cache operation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function executeWithProfiling(callable $operation, string $identifier): mixed
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        try {
            $result = $this->security->executeCriticalOperation(
                new ProfilingOperation($identifier),
                new SecurityContext(['type' => 'performance_profiling']),
                fn() => $operation()
            );

            $this->recordMetrics(
                $identifier,
                microtime(true) - $startTime,
                memory_get_usage(true) - $startMemory
            );

            return $result;
            
        } catch (\Exception $e) {
            $this->metrics->recordOperationFailure($identifier, $e);
            throw new PerformanceException('Operation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function optimizeQuery(string $sql, array $params = []): OptimizedQuery
    {
        return $this->executeWithProfiling(
            fn() => $this->queryOptimizer->optimize($sql, $params),
            'query_optimization'
        );
    }

    public function invalidateCache(string $key, array $tags = []): bool
    {
        try {
            if (empty($tags)) {
                return Cache::forget(self::CACHE_PREFIX . $key);
            }
            return Cache::tags($tags)->forget(self::CACHE_PREFIX . $key);
        } catch (InvalidArgumentException $e) {
            $this->metrics->recordCacheError($key, $e);
            throw new CacheException('Cache invalidation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function invalidateTags(array $tags): bool
    {
        try {
            return Cache::tags($tags)->flush();
        } catch (InvalidArgumentException $e) {
            $this->metrics->recordCacheError(implode(',', $tags), $e);
            throw new CacheException('Tags invalidation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function recordMetrics(string $identifier, float $duration, int $memory): void
    {
        $metrics = [
            'duration' => $duration,
            'memory' => $memory,
            'timestamp' => microtime(true),
            'cpu_usage' => sys_getloadavg()[0]
        ];

        $this->metrics->record($identifier, $metrics);

        if ($duration > $this->config->getSlowOperationThreshold()) {
            $this->metrics->recordSlowOperation($identifier, $metrics);
        }

        if ($memory > $this->config->getHighMemoryThreshold()) {
            $this->metrics->recordHighMemoryUsage($identifier, $metrics);
        }
    }

    public function getPerformanceMetrics(string $identifier): array
    {
        return $this->metrics->getMetrics($identifier);
    }

    public function optimizePerformance(): void
    {
        $this->executeWithProfiling(function() {
            $this->garbageCollector->collect();
            $this->queryOptimizer->optimizeConnections();
            $this->cacheRepo->optimize();
        }, 'performance_optimization');
    }
}

class QueryOptimizer
{
    private const MAX_QUERY_TIME = 50; // milliseconds

    public function optimize(string $sql, array $params = []): OptimizedQuery
    {
        $startTime = microtime(true);
        
        try {
            $explainResults = DB::select('EXPLAIN ' . $sql, $params);
            $optimized = $this->analyzeAndOptimize($sql, $explainResults);
            
            if ((microtime(true) - $startTime) * 1000 > self::MAX_QUERY_TIME) {
                throw new PerformanceException('Query optimization exceeded time limit');
            }

            return new OptimizedQuery($optimized, $params);
        } catch (\Exception $e) {
            throw new PerformanceException('Query optimization failed: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function analyzeAndOptimize(string $sql, array $explainResults): string
    {
        // Query optimization logic here
        return $sql;
    }
}

class MetricsCollector
{
    private const METRICS_TTL = 86400; // 24 hours

    public function record(string $identifier, array $metrics): void
    {
        $key = self::METRICS_PREFIX . $identifier;
        
        $existing = Cache::get($key, []);
        $existing[] = $metrics;
        
        Cache::put($key, $existing, self::METRICS_TTL);
    }

    public function getMetrics(string $identifier): array
    {
        return Cache::get(self::METRICS_PREFIX . $identifier, []);
    }
}
