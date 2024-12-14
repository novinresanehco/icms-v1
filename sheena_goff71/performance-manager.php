<?php

namespace App\Core\Performance;

use App\Core\Monitoring\MonitoringService;
use App\Core\Cache\CacheManager;
use App\Exceptions\PerformanceException;

class PerformanceManager implements PerformanceInterface
{
    private MonitoringService $monitor;
    private CacheManager $cache;
    private array $config;
    private array $metrics = [];

    public function __construct(
        MonitoringService $monitor,
        CacheManager $cache,
        array $config
    ) {
        $this->monitor = $monitor;
        $this->cache = $cache;
        $this->config = $config;
    }

    public function trackOperation(string $operation, callable $callback): mixed
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        try {
            // Set performance constraints
            $this->setResourceLimits($operation);
            
            // Execute operation
            $result = $callback();
            
            // Record metrics
            $this->recordOperationMetrics($operation, [
                'duration' => microtime(true) - $startTime,
                'memory_usage' => memory_get_usage(true) - $startMemory,
                'success' => true
            ]);

            return $result;

        } catch (\Throwable $e) {
            $this->recordOperationMetrics($operation, [
                'duration' => microtime(true) - $startTime,
                'memory_usage' => memory_get_usage(true) - $startMemory,
                'success' => false,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }

    public function optimizePerformance(string $context): void
    {
        // Get current metrics
        $metrics = $this->getCurrentMetrics($context);
        
        // Check for optimization opportunities
        $optimizations = $this->analyzeOptimizationOpportunities($metrics);
        
        // Apply optimizations
        foreach ($optimizations as $optimization) {
            $this->applyOptimization($optimization, $context);
        }
        
        // Verify improvements
        $this->verifyOptimizationResults($context, $metrics);
    }

    public function getPerformanceReport(string $context): array
    {
        return [
            'metrics' => $this->getMetrics($context),
            'thresholds' => $this->getThresholds($context),
            'optimizations' => $this->getAppliedOptimizations($context),
            'recommendations' => $this->generateRecommendations($context)
        ];
    }

    private function setResourceLimits(string $operation): void
    {
        $limits = $this->config['operations'][$operation]['limits'] ?? $this->config['default_limits'];
        
        if (isset($limits['memory'])) {
            ini_set('memory_limit', $limits['memory']);
        }
        
        if (isset($limits['time'])) {
            set_time_limit($limits['time']);
        }
    }

    private function recordOperationMetrics(string $operation, array $metrics): void
    {
        // Store metrics
        $this->metrics[$operation][] = $metrics;
        
        // Record in monitoring service
        $this->monitor->recordMetric("performance.$operation", $metrics);
        
        // Check thresholds
        $this->checkPerformanceThresholds($operation, $metrics);
        
        // Cache recent metrics
        $this->cacheMetrics($operation, $metrics);
    }

    private function checkPerformanceThresholds(string $operation, array $metrics): void
    {
        $thresholds = $this->config['operations'][$operation]['thresholds'] ?? $this->config['default_thresholds'];
        
        foreach ($thresholds as $metric => $threshold) {
            if (isset($metrics[$metric]) && $metrics[$metric] > $threshold) {
                $this->monitor->triggerAlert('performance_threshold_exceeded', [
                    'operation' => $operation,
                    'metric' => $metric,
                    'value' => $metrics[$metric],
                    'threshold' => $threshold
                ], 'critical');
            }
        }
    }

    private function analyzeOptimizationOpportunities(array $metrics): array
    {
        $opportunities = [];
        
        // Check response times
        if ($this->isResponseTimeOptimizable($metrics)) {
            $opportunities[] = [
                'type' => 'response_time',
                'priority' => 'high',
                'potential_gain' => $this->calculatePotentialGain($metrics['response_time'])
            ];
        }
        
        // Check memory usage
        if ($this->isMemoryUsageOptimizable($metrics)) {
            $opportunities[] = [
                'type' => 'memory_usage',
                'priority' => 'medium',
                'potential_gain' => $this->calculatePotentialGain($metrics['memory_usage'])
            ];
        }
        
        // Check query performance
        if ($this->isQueryPerformanceOptimizable($metrics)) {
            $opportunities[] = [
                'type' => 'query_performance',
                'priority' => 'high',
                'potential_gain' => $this->calculatePotentialGain($metrics['query_time'])
            ];
        }
        
        return $opportunities;
    }

    private function applyOptimization(array $optimization, string $context): void
    {
        switch ($optimization['type']) {
            case 'response_time':
                $this->optimizeResponseTime($context);
                break;
            
            case 'memory_usage':
                $this->optimizeMemoryUsage($context);
                break;
            
            case 'query_performance':
                $this->optimizeQueryPerformance($context);
                break;
        }
        
        // Record applied optimization
        $this->recordOptimization($optimization, $context);
    }

    private function verifyOptimizationResults(string $context, array $originalMetrics): void
    {
        $newMetrics = $this->getCurrentMetrics($context);
        
        $improvements = $this->calculateImprovements($originalMetrics, $newMetrics);
        
        if (!$this->areImprovementsSufficient($improvements)) {
            $this->monitor->triggerAlert('optimization_insufficient', [
                'context' => $context,
                'improvements' => $improvements
            ], 'warning');
        }
    }

    private function cacheMetrics(string $operation, array $metrics): void
    {
        $key = "performance:metrics:$operation";
        $cached = $this->cache->get($key, []);
        
        // Add new metrics
        array_push($cached, [
            'timestamp' => microtime(true),
            'metrics' => $metrics
        ]);
        
        // Keep only recent metrics
        $cached = array_filter($cached, function($item) {
            return (microtime(true) - $item['timestamp']) <= $this->config['metrics_ttl'];
        });
        
        $this->cache->set($key, $cached, $this->config['metrics_ttl']);
    }
}
