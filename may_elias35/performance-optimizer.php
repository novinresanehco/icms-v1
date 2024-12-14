<?php

namespace App\Core\Performance;

use App\Core\Monitoring\SystemMonitor;
use App\Core\Cache\CacheManager;
use App\Core\Exceptions\PerformanceException;

class PerformanceOptimizer implements PerformanceInterface
{
    private SystemMonitor $monitor;
    private CacheManager $cache;
    private array $config;
    private array $metrics = [];

    public function __construct(
        SystemMonitor $monitor,
        CacheManager $cache,
        array $config
    ) {
        $this->monitor = $monitor;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function optimizeQuery(string $query): string
    {
        $monitoringId = $this->monitor->startOperation('query_optimization');
        
        try {
            $this->validateQuery($query);
            
            $optimized = $this->cache->remember(
                $this->getQueryCacheKey($query),
                fn() => $this->performQueryOptimization($query),
                $this->config['query_cache_ttl']
            );
            
            $this->monitor->recordSuccess($monitoringId);
            
            return $optimized;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new PerformanceException('Query optimization failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function optimizeResponse(array $data): array
    {
        $monitoringId = $this->monitor->startOperation('response_optimization');
        
        try {
            $this->validateResponseData($data);
            
            $optimized = $this->performResponseOptimization($data);
            $this->recordMetrics('response_optimization', $data, $optimized);
            
            $this->monitor->recordSuccess($monitoringId);
            
            return $optimized;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new PerformanceException('Response optimization failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function optimizeResource(string $type, mixed $resource): mixed
    {
        $monitoringId = $this->monitor->startOperation('resource_optimization');
        
        try {
            $this->validateResourceType($type);
            $this->validateResource($resource);
            
            $optimized = $this->performResourceOptimization($type, $resource);
            $this->recordMetrics('resource_optimization', $resource, $optimized);
            
            $this->monitor->recordSuccess($monitoringId);
            
            return $optimized;
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            throw new PerformanceException('Resource optimization failed: ' . $e->getMessage(), 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function validateQuery(string $query): void
    {
        if (empty($query)) {
            throw new PerformanceException('Empty query');
        }

        if (strlen($query) > $this->config['max_query_length']) {
            throw new PerformanceException('Query exceeds maximum length');
        }
    }

    private function performQueryOptimization(string $query): string
    {
        $optimizer = $this->getQueryOptimizer();
        return $optimizer->optimize($query);
    }

    private function validateResponseData(array $data): void
    {
        $size = strlen(json_encode($data));
        
        if ($size > $this->config['max_response_size']) {
            throw new PerformanceException('Response size exceeds limit');
        }
    }

    private function performResponseOptimization(array $data): array
    {
        // Remove unnecessary fields
        $data = $this->removeUnusedFields($data);
        
        // Optimize nested structures
        $data = $this->optimizeNestedData($data);
        
        // Compress data if needed
        if ($this->shouldCompressData($data)) {
            $data = $this->compressData($data);
        }
        
        return $data;
    }

    private function validateResourceType(string $type): void
    {
        if (!in_array($type, $this->config['supported_resource_types'])) {
            throw new PerformanceException('Unsupported resource type');
        }
    }

    private function validateResource(mixed $resource): void
    {
        if (!$this->isValidResource($resource)) {
            throw new PerformanceException('Invalid resource');
        }
    }

    private function performResourceOptimization(string $type, mixed $resource): mixed
    {
        $optimizer = $this->getResourceOptimizer($type);
        return $optimizer->optimize($resource);
    }

    private function getQueryOptimizer(): QueryOptimizerInterface
    {
        return new QueryOptimizer($this->config['query_optimization']);
    }

    private function removeUnusedFields(array $data): array
    {
        $fields = $this->config['required_fields'];
        
        return array_filter($data, function($key) use ($fields) {
            return in_array($key, $fields);
        }, ARRAY_FILTER_USE_KEY);
    }

    private function optimizeNestedData(array $data): array
    {
        array_walk_recursive($data, function(&$value) {
            if (is_string($value)) {
                $value = $this->optimizeString($value);
            }
        });
        
        return $data;
    }

    private function optimizeString(string $value): string
    {
        $value = trim($value);
        
        if (strlen($value) > $this->config['max_string_length']) {
            $value = substr($value, 0, $this->config['max_string_length']);
        }
        
        return $value;
    }

    private function shouldCompressData(array $data): bool
    {
        $size = strlen(json_encode($data));
        return $size > $this->config['compression_threshold'];
    }

    private function compressData(array $data): array
    {
        return $this->getCompressionStrategy()->compress($data);
    }

    private function getResourceOptimizer(string $type): ResourceOptimizerInterface
    {
        $class = $this->config['resource_optimizers'][$type] ?? null;
        
        if (!$class || !class_exists($class)) {
            throw new PerformanceException("Optimizer not found for type: {$type}");
        }
        
        return new $class($this->config['resource_optimization']);
    }

    private function isValidResource(mixed $resource): bool
    {
        return true; // Implementation depends on resource type
    }

    private function getQueryCacheKey(string $query): string
    {
        return 'query_optimization:' . md5($query);
    }

    private function recordMetrics(string $type, mixed $original, mixed $optimized): void
    {
        $this->metrics[$type] = [
            'original_size' => $this->calculateSize($original),
            'optimized_size' => $this->calculateSize($optimized),
            'optimization_ratio' => $this->calculateOptimizationRatio($original, $optimized)
        ];
    }

    private function calculateSize(mixed $data): int
    {
        return strlen(serialize($data));
    }

    private function calculateOptimizationRatio(mixed $original, mixed $optimized): float
    {
        $originalSize = $this->calculateSize($original);
        $optimizedSize = $this->calculateSize($optimized);
        
        return ($originalSize - $optimizedSize) / $originalSize * 100;
    }

    private function getCompressionStrategy(): CompressionStrategyInterface