<?php

namespace App\Core\Cache;

use Illuminate\Support\Facades\Cache;
use App\Core\Security\SecurityManagerInterface;
use App\Core\Monitoring\MonitoringServiceInterface;
use App\Core\Validation\ValidationServiceInterface;
use App\Exceptions\CacheException;

class CacheManager implements CacheManagerInterface
{
    private SecurityManagerInterface $security;
    private MonitoringServiceInterface $monitor;
    private ValidationServiceInterface $validator;
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        MonitoringServiceInterface $monitor,
        ValidationServiceInterface $validator,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->validator = $validator;
        $this->config = $config;
    }

    /**
     * Secure cache storage with monitoring
     */
    public function store(string $key, mixed $value, ?int $ttl = null): bool
    {
        $operationId = $this->monitor->startOperation('cache.store');

        try {
            // Validate key and value
            $this->validateCacheOperation($key, $value);

            // Generate secure cache key
            $secureKey = $this->security->generateCacheKey($key);

            // Encrypt sensitive data
            $secureValue = $this->security->encryptCacheData($value);

            // Store with monitoring
            $stored = Cache::put(
                $secureKey,
                $secureValue,
                $ttl ?? $this->config['default_ttl']
            );

            if ($stored) {
                $this->monitor->recordMetric('cache.store.success', 1);
            }

            return $stored;

        } catch (\Throwable $e) {
            $this->handleCacheFailure('store', $e, $operationId);
            throw $e;
        } finally {
            $this->monitor->stopOperation($operationId);
        }
    }

    /**
     * Secure cache retrieval with validation
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $operationId = $this->monitor->startOperation('cache.get');

        try {
            // Generate secure key
            $secureKey = $this->security->generateCacheKey($key);

            // Retrieve with validation
            $value = Cache::get($secureKey);

            if ($value !== null) {
                // Decrypt and verify data
                $decrypted = $this->security->decryptCacheData($value);
                
                // Validate data integrity
                if ($this->validateCacheData($decrypted)) {
                    $this->monitor->recordMetric('cache.hit', 1);
                    return $decrypted;
                }
            }

            $this->monitor->recordMetric('cache.miss', 1);
            return $default;

        } catch (\Throwable $e) {
            $this->handleCacheFailure('get', $e, $operationId);
            throw $e;
        } finally {
            $this->monitor->stopOperation($operationId);
        }
    }

    /**
     * Remember value with security checks
     */
    public function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $operationId = $this->monitor->startOperation('cache.remember');

        try {
            $value = $this->get($key);

            if ($value !== null) {
                return $value;
            }

            // Execute callback with resource limits
            $value = $this->executeWithLimits($callback);

            // Store result
            $this->store($key, $value, $ttl);

            return $value;

        } catch (\Throwable $e) {
            $this->handleCacheFailure('remember', $e, $operationId);
            throw $e;
        } finally {
            $this->monitor->stopOperation($operationId);
        }
    }

    /**
     * Secure cache invalidation
     */
    public function invalidate(string $key): bool
    {
        $operationId = $this->monitor->startOperation('cache.invalidate');

        try {
            $secureKey = $this->security->generateCacheKey($key);
            
            $result = Cache::forget($secureKey);
            
            if ($result) {
                $this->monitor->recordMetric('cache.invalidate.success', 1);
            }

            return $result;

        } catch (\Throwable $e) {
            $this->handleCacheFailure('invalidate', $e, $operationId);
            throw $e;
        } finally {
            $this->monitor->stopOperation($operationId);
        }
    }

    /**
     * Pattern-based cache invalidation
     */
    public function invalidatePattern(string $pattern): void
    {
        $operationId = $this->monitor->startOperation('cache.invalidate_pattern');

        try {
            $securePattern = $this->security->generateCacheKey($pattern);
            
            // Find all matching keys
            $keys = $this->findMatchingKeys($securePattern);
            
            // Invalidate all matching keys
            foreach ($keys as $key) {
                Cache::forget($key);
            }

            $this->monitor->recordMetric('cache.pattern_invalidate.count', count($keys));

        } catch (\Throwable $e) {
            $this->handleCacheFailure('invalidate_pattern', $e, $operationId);
            throw $e;
        } finally {
            $this->monitor->stopOperation($operationId);
        }
    }

    private function validateCacheOperation(string $key, mixed $value): void
    {
        // Validate key
        if (strlen($key) > $this->config['max_key_length']) {
            throw new CacheException('Cache key exceeds maximum length');
        }

        // Validate value size
        $size = $this->calculateDataSize($value);
        if ($size > $this->config['max_value_size']) {
            throw new CacheException('Cache value exceeds size limit');
        }

        // Validate data structure
        $this->validator->validateOperation('cache.store', [
            'key' => $key,
            'value' => $value
        ]);
    }

    private function validateCacheData(mixed $data): bool
    {
        try {
            return $this->validator->validateOperation('cache.retrieve', [
                'data' => $data
            ]);
        } catch (\Throwable $e) {
            $this->monitor->recordMetric('cache.validation.failure', 1);
            return false;
        }
    }

    private function executeWithLimits(callable $callback): mixed
    {
        return $this->security->executeWithResourceLimits(
            $callback,
            [
                'memory_limit' => $this->config['callback_memory_limit'],
                'time_limit' => $this->config['callback_time_limit']
            ]
        );
    }

    private function handleCacheFailure(string $operation, \Throwable $e, string $operationId): void
    {
        $this->monitor->recordMetric('cache.failure', [
            'operation' => $operation,
            'error' => $e->getMessage()
        ]);

        $this->monitor->triggerAlert('cache_operation_failed', [
            'operation' => $operation,
            'operation_id' => $operationId,
            'error' => $e->getMessage()
        ]);
    }
}
