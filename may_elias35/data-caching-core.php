<?php

namespace App\Core\Data;

class DataManager implements DataManagerInterface
{
    private Repository $repository;
    private CacheManager $cache;
    private SecurityManager $security;
    private ValidationService $validator;
    private MetricsCollector $metrics;

    public function __construct(
        Repository $repository,
        CacheManager $cache,
        SecurityManager $security,
        ValidationService $validator,
        MetricsCollector $metrics
    ) {
        $this->repository = $repository;
        $this->cache = $cache;
        $this->security = $security;
        $this->validator = $validator;
        $this->metrics = $metrics;
    }

    public function retrieve(string $key, array $options = []): DataResult
    {
        $startTime = microtime(true);

        try {
            $this->validateKey($key);
            
            return $this->cache->remember("data:$key", 3600, function() use ($key) {
                $data = $this->repository->find($key);
                $this->validateData($data);
                return $data;
            });
        } catch (\Exception $e) {
            $this->handleError('retrieve', $key, $e);
            throw $e;
        } finally {
            $this->recordMetrics('retrieve', microtime(true) - $startTime);
        }
    }

    public function store(string $key, $data, array $options = []): bool
    {
        $startTime = microtime(true);
        DB::beginTransaction();

        try {
            $this->validateKey($key);
            $this->validateData($data);

            $result = $this->repository->store($key, $data);
            $this->cache->invalidate("data:$key");
            
            DB::commit();
            return $result;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleError('store', $key, $e);
            throw $e;
        } finally {
            $this->recordMetrics('store', microtime(true) - $startTime);
        }
    }

    private function validateKey(string $key): void
    {
        if (empty($key) || strlen($key) > 255) {
            throw new ValidationException('Invalid key format');
        }
    }

    private function validateData($data): void
    {
        if (empty($data)) {
            throw new ValidationException('Empty data not allowed');
        }

        if (!$this->validator->validate($data)) {
            throw new ValidationException('Data validation failed');
        }

        if (!$this->security->validateDataIntegrity($data)) {
            throw new SecurityException('Data integrity check failed');
        }
    }

    private function handleError(string $operation, string $key, \Exception $e): void
    {
        Log::error("Data operation failed: $operation", [
            'key' => $key,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->metrics->increment('data.errors', [
            'operation' => $operation,
            'error_type' => get_class($e)
        ]);
    }

    private function recordMetrics(string $operation, float $duration): void
    {
        $this->metrics->timing("data.$operation.duration", $duration * 1000);
        $this->metrics->increment("data.$operation.calls");
    }
}

class CacheManager implements CacheManagerInterface
{
    private array $stores;
    private MetricsCollector $metrics;
    private array $config;

    public function remember(string $key, int $ttl, callable $callback)
    {
        $startTime = microtime(true);

        try {
            if ($cached = $this->get($key)) {
                $this->recordHit($key, microtime(true) - $startTime);
                return $cached;
            }

            $value = $callback();
            $this->put($key, $value, $ttl);
            $this->recordMiss($key, microtime(true) - $startTime);

            return $value;
        } catch (\Exception $e) {
            $this->handleError($key, $e);
            throw $e;
        }
    }

    public function get(string $key)
    {
        $startTime = microtime(true);

        try {
            $value = $this->stores['primary']->get($key);
            
            if ($value === null && isset($this->stores['fallback'])) {
                $value = $this->stores['fallback']->get($key);
                if ($value !== null) {
                    $this->stores['primary']->put($key, $value);
                }
            }

            $this->recordMetrics('get', microtime(true) - $startTime, $value !== null);
            return $value;

        } catch (\Exception $e) {
            $this->handleError($key, $e);
            return null;
        }
    }

    public function put(string $key, $value, int $ttl = null): bool
    {
        $startTime = microtime(true);

        try {
            $success = $this->stores['primary']->put(
                $key, 
                $value, 
                $ttl ?? $this->config['default_ttl']
            );

            if ($success && isset($this->stores['fallback'])) {
                $this->stores['fallback']->put($key, $value, $ttl);
            }

            $this->recordMetrics('put', microtime(true) - $startTime, $success);
            return $success;

        } catch (\Exception $e) {
            $this->handleError($key, $e);
            return false;
        }
    }

    private function recordHit(string $key, float $duration): void
    {
        $this->metrics->increment('cache.hits');
        $this->metrics->timing('cache.hit_time', $duration * 1000);
    }

    private function recordMiss(string $key, float $duration): void
    {
        $this->metrics->increment('cache.misses');
        $this->metrics->timing('cache.miss_time', $duration * 1000);
    }

    private function handleError(string $key, \Exception $e): void
    {
        $this->metrics->increment('cache.errors');
        Log::error("Cache operation failed for key: $key", [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function recordMetrics(string $operation, float $duration, bool $success): void
    {
        $this->metrics->timing("cache.$operation.duration", $duration * 1000);
        $this->metrics->increment("cache.$operation." . ($success ? 'success' : 'failure'));
    }
}
