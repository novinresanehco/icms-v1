<?php

namespace App\Core\Performance;

use Illuminate\Support\Facades\{Cache, Redis, Log};
use App\Core\Exceptions\CacheException;
use Illuminate\Cache\TaggedCache;

class CacheManager implements CacheInterface
{
    private string $prefix;
    private array $config;
    private MetricsCollector $metrics;
    private ValidationService $validator;

    public function __construct(
        MetricsCollector $metrics,
        ValidationService $validator,
        array $config = []
    ) {
        $this->prefix = config('cache.prefix', 'cms');
        $this->config = $config;
        $this->metrics = $metrics;
        $this->validator = $validator;
    }

    public function remember(string $key, mixed $data, ?int $ttl = null): mixed
    {
        $cacheKey = $this->getCacheKey($key);
        $ttl = $ttl ?? $this->getDefaultTTL();

        try {
            return Cache::tags($this->getTags($key))
                ->remember($cacheKey, $ttl, function() use ($data, $key) {
                    $value = $data instanceof \Closure ? $data() : $data;
                    $this->validateCacheData($value);
                    $this->metrics->incrementCacheStore($key);
                    return $value;
                });

        } catch (\Exception $e) {
            $this->handleCacheFailure('store', $key, $e);
            return $data instanceof \Closure ? $data() : $data;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $cacheKey = $this->getCacheKey($key);

        try {
            $value = Cache::tags($this->getTags($key))->get($cacheKey);
            
            if ($value !== null) {
                $this->metrics->incrementCacheHit($key);
                return $value;
            }

            $this->metrics->incrementCacheMiss($key);
            return $default instanceof \Closure ? $default() : $default;

        } catch (\Exception $e) {
            $this->handleCacheFailure('retrieve', $key, $e);
            return $default instanceof \Closure ? $default() : $default;
        }
    }

    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        $cacheKey = $this->getCacheKey($key);
        $ttl = $ttl ?? $this->getDefaultTTL();

        try {
            $this->validateCacheData($value);
            
            $success = Cache::tags($this->getTags($key))
                ->put($cacheKey, $value, $ttl);

            if ($success) {
                $this->metrics->incrementCacheStore($key);
            }

            return $success;

        } catch (\Exception $e) {
            $this->handleCacheFailure('store', $key, $e);
            return false;
        }
    }

    public function invalidate(string|array $keys): void
    {
        try {
            $keys = (array)$keys;
            foreach ($keys as $key) {
                Cache::tags($this->getTags($key))->forget($this->getCacheKey($key));
                $this->metrics->incrementCacheInvalidation($key);
            }
            
        } catch (\Exception $e) {
            $this->handleCacheFailure('invalidate', implode(',', $keys), $e);
        }
    }

    public function flush(string $tag = null): void
    {
        try {
            if ($tag) {
                Cache::tags($tag)->flush();
            } else {
                Cache::flush();
            }
            
            $this->metrics->incrementCacheFlush($tag ?? 'all');

        } catch (\Exception $e) {
            $this->handleCacheFailure('flush', $tag ?? 'all', $e);
        }
    }

    public function invalidatePattern(string $pattern): void
    {
        try {
            $keys = $this->getKeysByPattern($pattern);
            foreach ($keys as $key) {
                $this->invalidate($key);
            }
            
        } catch (\Exception $e) {
            $this->handleCacheFailure('pattern_invalidate', $pattern, $e);
        }
    }

    public function getMultiple(array $keys, mixed $default = null): array
    {
        $result = [];
        $cacheMisses = [];

        try {
            foreach ($keys as $key) {
                $value = $this->get($key);
                if ($value === null) {
                    $cacheMisses[] = $key;
                    $result[$key] = $default instanceof \Closure ? $default() : $default;
                } else {
                    $result[$key] = $value;
                }
            }

            if (!empty($cacheMisses)) {
                $this->metrics->recordBulkCacheMiss($cacheMisses);
            }

            return $result;

        } catch (\Exception $e) {
            $this->handleCacheFailure('retrieve_multiple', implode(',', $keys), $e);
            return array_fill_keys($keys, $default instanceof \Closure ? $default() : $default);
        }
    }

    public function putMultiple(array $values, ?int $ttl = null): bool
    {
        $ttl = $ttl ?? $this->getDefaultTTL();
        $success = true;

        try {
            foreach ($values as $key => $value) {
                if (!$this->put($key, $value, $ttl)) {
                    $success = false;
                }
            }

            return $success;

        } catch (\Exception $e) {
            $this->handleCacheFailure('store_multiple', 'bulk_operation', $e);
            return false;
        }
    }

    protected function getCacheKey(string $key): string
    {
        return "{$this->prefix}:{$key}";
    }

    protected function getTags(string $key): array
    {
        $parts = explode(':', $key);
        return array_merge([$this->prefix], array_slice($parts, 0, -1));
    }

    protected function getDefaultTTL(): int
    {
        return $this->config['ttl'] ?? config('cache.ttl', 3600);
    }

    protected function validateCacheData($data): void
    {
        if (!$this->validator->validateCacheData($data)) {
            throw new CacheException('Invalid cache data format');
        }
    }

    protected function getKeysByPattern(string $pattern): array
    {
        return Redis::keys($this->getCacheKey($pattern));
    }

    protected function handleCacheFailure(string $operation, string $key, \Exception $e): void
    {
        $this->metrics->incrementCacheFailure($operation);
        
        Log::error('Cache operation failed', [
            'operation' => $operation,
            'key' => $key,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

class PerformanceMonitor implements PerformanceInterface
{
    private MetricsCollector $metrics;
    private array $thresholds;

    public function __construct(MetricsCollector $metrics)
    {
        $this->metrics = $metrics;
        $this->thresholds = config('performance.thresholds', []);
    }

    public function recordOperation(string $operation, float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        $this->metrics->recordOperationDuration($operation, $duration);

        if ($duration > ($this->thresholds[$operation] ?? 0)) {
            $this->handleSlowOperation($operation, $duration);
        }
    }

    public function startOperation(string $operation): string
    {
        $id = uniqid('op_');
        $this->metrics->startOperation($id, $operation);
        return $id;
    }

    public function endOperation(string $id): void
    {
        $this->metrics->endOperation($id);
    }

    public function recordResourceUsage(): void
    {
        $this->metrics->recordMemoryUsage(memory_get_usage(true));
        $this->metrics->recordCpuUsage();
    }

    protected function handleSlowOperation(string $operation, float $duration): void
    {
        Log::warning('Slow operation detected', [
            'operation' => $operation,
            'duration' => $duration,
            'threshold' => $this->thresholds[$operation] ?? 0
        ]);
    }
}
