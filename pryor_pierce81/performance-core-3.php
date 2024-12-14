<?php

namespace App\Core\Performance;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\PerformanceException;
use Psr\Log\LoggerInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PerformanceManager implements PerformanceManagerInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $config;
    private array $metrics = [];

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function optimizeQuery(string $query, array $params = []): string
    {
        $operationId = $this->generateOperationId();

        try {
            $this->security->validateSecureOperation('performance:query', [
                'operation_id' => $operationId
            ]);

            $this->validateQuery($query);
            $optimizedQuery = $this->executeQueryOptimization($query, $params);
            
            $this->logOptimization($operationId, 'query', $query);
            
            return $optimizedQuery;

        } catch (\Exception $e) {
            $this->handleOptimizationFailure($operationId, 'query', $e);
            throw new PerformanceException('Query optimization failed', 0, $e);
        }
    }

    public function cacheData(string $key, $data, int $ttl = null): bool
    {
        $operationId = $this->generateOperationId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('performance:cache', [
                'operation_id' => $operationId
            ]);

            $this->validateCacheKey($key);
            $this->validateCacheData($data);
            
            $success = $this->executeCacheOperation($key, $data, $ttl);
            
            $this->logCacheOperation($operationId, 'store', $key);

            DB::commit();
            return $success;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleCacheFailure($operationId, 'store', $e);
            throw new PerformanceException('Cache operation failed', 0, $e);
        }
    }

    public function optimizeResponse($data, array $options = [])
    {
        $operationId = $this->generateOperationId();

        try {
            $this->security->validateSecureOperation('performance:response', [
                'operation_id' => $operationId
            ]);

            $optimizedData = $this->executeResponseOptimization($data, $options);
            $this->logOptimization($operationId, 'response', gettype($data));
            
            return $optimizedData;

        } catch (\Exception $e) {
            $this->handleOptimizationFailure($operationId, 'response', $e);
            throw new PerformanceException('Response optimization failed', 0, $e);
        }
    }

    public function monitorPerformance(string $component): array
    {
        $operationId = $this->generateOperationId();

        try {
            $this->security->validateSecureOperation('performance:monitor', [
                'operation_id' => $operationId,
                'component' => $component
            ]);

            return $this->collectPerformanceMetrics($component);

        } catch (\Exception $e) {
            $this->handleMonitoringFailure($operationId, $component, $e);
            throw new PerformanceException('Performance monitoring failed', 0, $e);
        }
    }

    private function validateQuery(string $query): void
    {
        if (empty(trim($query))) {
            throw new PerformanceException('Empty query string');
        }

        if (strlen($query) > $this->config['max_query_length']) {
            throw new PerformanceException('Query exceeds maximum length');
        }
    }

    private function validateCacheKey(string $key): void
    {
        if (!preg_match($this->config['key_pattern'], $key)) {
            throw new PerformanceException('Invalid cache key format');
        }

        if (strlen($key) > $this->config['max_key_length']) {
            throw new PerformanceException('Cache key exceeds maximum length');
        }
    }

    private function validateCacheData($data): void
    {
        $serialized = serialize($data);
        
        if (strlen($serialized) > $this->config['max_data_size']) {
            throw new PerformanceException('Cache data exceeds size limit');
        }
    }

    private function executeQueryOptimization(string $query, array $params): string
    {
        $explainResults = DB::select("EXPLAIN $query", $params);
        $optimizationRules = $this->loadOptimizationRules();
        
        return $this->applyQueryOptimizations($query, $explainResults, $optimizationRules);
    }

    private function executeCacheOperation(string $key, $data, ?int $ttl): bool
    {
        $ttl = $ttl ?? $this->config['default_ttl'];
        $processedData = $this->prepareCacheData($data);
        
        return Cache::put($key, $processedData, $ttl);
    }

    private function executeResponseOptimization($data, array $options): mixed
    {
        if (is_array($data)) {
            return $this->optimizeArrayResponse($data, $options);
        }
        
        if (is_object($data)) {
            return $this->optimizeObjectResponse($data, $options);
        }
        
        return $data;
    }

    private function optimizeArrayResponse(array $data, array $options): array
    {
        $optimized = [];
        
        foreach ($data as $key => $value) {
            if ($this->shouldIncludeField($key, $options)) {
                $optimized[$key] = $this->executeResponseOptimization($value, $options);
            }
        }
        
        return $optimized;
    }

    private function optimizeObjectResponse(object $data, array $options): object
    {
        if (method_exists($data, 'toArray')) {
            $array = $data->toArray();
            return (object)$this->optimizeArrayResponse($array, $options);
        }
        
        return $data;
    }

    private function collectPerformanceMetrics(string $component): array
    {
        $metrics = [
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'cpu_usage' => sys_getloadavg()[0],
            'response_time' => $this->calculateResponseTime(),
            'database_stats' => $this->getDatabaseMetrics(),
            'cache_stats' => $this->getCacheMetrics()
        ];

        $this->storeMetrics($component, $metrics);
        return $metrics;
    }

    private function shouldIncludeField(string $field, array $options): bool
    {
        if (empty($options['fields'])) {
            return true;
        }
        
        return in_array($field, $options['fields']);
    }

    private function prepareCacheData($data): mixed
    {
        if ($this->config['compression_enabled']) {
            return $this->compressData($data);
        }
        
        return $data;
    }

    private function compressData($data): string
    {
        $serialized = serialize($data);
        return gzcompress($serialized, $this->config['compression_level']);
    }

    private function getDefaultConfig(): array
    {
        return [
            'max_query_length' => 10000,
            'max_key_length' => 250,
            'max_data_size' => 1024 * 1024,
            'default_ttl' => 3600,
            'compression_enabled' => true,
            'compression_level' => 6,
            'key_pattern' => '/^[a-zA-Z0-9:._-]+$/',
            'monitoring_interval' => 60
        ];
    }

    private function generateOperationId(): string
    {
        return uniqid('perf_', true);
    }

    private function handleOptimizationFailure(string $operationId, string $type, \Exception $e): void
    {
        $this->logger->error('Optimization operation failed', [
            'operation_id' => $operationId,
            'type' => $type,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function handleCacheFailure(string $operationId, string $operation, \Exception $e): void
    {
        $this->logger->error('Cache operation failed', [
            'operation_id' => $operationId,
            'operation' => $operation,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function handleMonitoringFailure(string $operationId, string $component, \Exception $e): void
    {
        $this->logger->error('Performance monitoring failed', [
            'operation_id' => $operationId,
            'component' => $component,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
