<?php
namespace App\Core\Performance;

class CriticalPerformanceKernel {
    private CacheManager $cache;
    private MetricsCollector $metrics;
    private SecurityValidator $security;
    private LogManager $logger;

    public function executeWithCache(string $key, callable $operation, array $context): mixed {
        try {
            // Security check
            $this->security->validateCacheAccess($key, $context);
            
            // Try cache first
            if ($cached = $this->getFromCache($key, $context)) {
                $this->metrics->recordCacheHit($key);
                return $cached;
            }

            // Execute and cache
            $result = $this->executeAndCache($key, $operation, $context);
            
            // Record metrics
            $this->metrics->recordOperation($key, $context);
            
            return $result;

        } catch (\Exception $e) {
            $this->handleCacheFailure($e, $key, $context);
            throw $e;
        }
    }

    private function executeAndCache(string $key, callable $operation, array $context): mixed {
        $startTime = microtime(true);
        
        try {
            DB::beginTransaction();
            
            $result = $operation();
            
            // Validate result before caching
            $this->validateResult($result);
            
            // Store in cache
            $this->cache->store($key, $result, $this->getCacheTTL($key));
            
            DB::commit();
            
            $this->recordPerformanceMetrics($key, microtime(true) - $startTime);
            
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function validateResult($result): void {
        if (!$this->isValidResult($result)) {
            throw new CacheException('Invalid result structure for caching');
        }
    }

    private function handleCacheFailure(\Exception $e, string $key, array $context): void {
        $this->logger->logCacheFailure($e, [
            'key' => $key,
            'context' => $context,
            'timestamp' => microtime(true)
        ]);
        
        $this->metrics->recordCacheFailure($key);
    }

    private function getCacheTTL(string $key): int {
        return config("cache.ttls.$key", 3600);
    }
}

class CacheManager {
    private CacheStore $store;
    private TagManager $tags;
    private MetricsCollector $metrics;

    public function store(string $key, mixed $data, int $ttl): void {
        if (!$this->isStorable($data)) {
            throw new CacheException('Data not suitable for caching');
        }

        $this->store->set($key, $this->prepareForCache($data), $ttl);
        $this->tags->tagKey($key);
        $this->metrics->recordCacheStore($key);
    }

    public function invalidate(string $key): void {
        $this->store->delete($key);
        $this->tags->removeKey($key);
        $this->metrics->recordCacheInvalidation($key);
    }

    public function invalidateTag(string $tag): void {
        $keys = $this->tags->getKeysByTag($tag);
        foreach ($keys as $key) {
            $this->invalidate($key);
        }
    }

    private function isStorable(mixed $data): bool {
        return !is_resource($data) && !is_callable($data);
    }

    private function prepareForCache(mixed $data): mixed {
        if (is_object($data)) {
            return $this->serializeObject($data);
        }
        return $data;
    }

    private function serializeObject(object $object): array {
        if (!$object instanceof \JsonSerializable) {
            throw new CacheException('Object must implement JsonSerializable');
        }
        return $object->jsonSerialize();
    }
}

class PerformanceMonitor {
    private MetricsCollector $metrics;
    private ThresholdManager $thresholds;
    private AlertSystem $alerts;

    public function checkPerformance(string $operation, float $executionTime): void {
        $threshold = $this->thresholds->getFor($operation);
        
        if ($executionTime > $threshold) {
            $this->handlePerformanceIssue($operation, $executionTime, $threshold);
        }

        $this->metrics->recordExecutionTime($operation, $executionTime);
    }

    private function handlePerformanceIssue(
        string $operation,
        float $executionTime,
        float $threshold
    ): void {
        $this->alerts->notifyPerformanceIssue([
            'operation' => $operation,
            'execution_time' => $executionTime,
            'threshold' => $threshold,
            'timestamp' => microtime(true)
        ]);

        $this->metrics->recordPerformanceIssue($operation);
    }
}

class MetricsAggregator {
    private MetricsStore $store;
    private AlertSystem $alerts;
    private ThresholdManager $thresholds;

    public function recordMetrics(string $category, array $metrics): void {
        $this->store->record($category, array_merge($metrics, [
            'timestamp' => microtime(true),
            'server_id' => gethostname()
        ]));

        $this->checkThresholds($category, $metrics);
    }

    private function checkThresholds(string $category, array $metrics): void {
        $thresholds = $this->thresholds->getForCategory($category);
        
        foreach ($thresholds as $metric => $threshold) {
            if (isset($metrics[$metric]) && $metrics[$metric] > $threshold) {
                $this->alerts->notifyThresholdExceeded($category, $metric, $metrics[$metric]);
            }
        }
    }
}

interface CacheStore {
    public function get(string $key): mixed;
    public function set(string $key, mixed $value, int $ttl): void;
    public function delete(string $key): void;
}

interface TagManager {
    public function tagKey(string $key): void;
    public function removeKey(string $key): void;
    public function getKeysByTag(string $tag): array;
}

interface MetricsStore {
    public function record(string $category, array $metrics): void;
    public function retrieve(string $category, array $filters = []): array;
}

interface ThresholdManager {
    public function getFor(string $operation): float;
    public function getForCategory(string $category): array;
}

interface AlertSystem {
    public function notifyPerformanceIssue(array $context): void;
    public function notifyThresholdExceeded(string $category, string $metric, float $value): void;
}

class CacheException extends \Exception {}
class PerformanceException extends \Exception {}
