```php
namespace App\Core\Performance;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Monitoring\MonitoringServiceInterface;
use App\Core\Cache\CacheManagerInterface;
use App\Core\Database\DatabaseManagerInterface;
use App\Exceptions\PerformanceException;

class PerformanceManager implements PerformanceManagerInterface
{
    private SecurityManagerInterface $security;
    private MonitoringServiceInterface $monitor;
    private CacheManagerInterface $cache;
    private DatabaseManagerInterface $database;
    private array $thresholds;

    public function __construct(
        SecurityManagerInterface $security,
        MonitoringServiceInterface $monitor,
        CacheManagerInterface $cache,
        DatabaseManagerInterface $database,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->cache = $cache;
        $this->database = $database;
        $this->thresholds = $config['thresholds'];
    }

    /**
     * Monitor and optimize critical system performance
     */
    public function monitorSystemPerformance(): void
    {
        $operationId = $this->monitor->startOperation('performance.monitor');

        try {
            // Check system resources
            $this->checkResourceUtilization();

            // Monitor response times
            $this->monitorResponseTimes();

            // Check cache efficiency
            $this->analyzeCacheEfficiency();

            // Monitor database performance
            $this->monitorDatabasePerformance();

            // Detect and resolve bottlenecks
            $this->detectBottlenecks();

        } catch (\Throwable $e) {
            $this->handleMonitoringFailure($e, $operationId);
            throw $e;
        } finally {
            $this->monitor->stopOperation($operationId);
        }
    }

    /**
     * Execute performance optimization with security verification
     */
    public function optimizeSystemPerformance(): void
    {
        $operationId = $this->monitor->startOperation('performance.optimize');

        try {
            $this->security->executeCriticalOperation(function() {
                // Optimize cache usage
                $this->optimizeCache();

                // Optimize database performance
                $this->optimizeDatabase();

                // Optimize resource usage
                $this->optimizeResources();

                // Verify optimizations
                $this->verifyOptimizations();
            }, ['context' => 'performance_optimization']);

        } catch (\Throwable $e) {
            $this->handleOptimizationFailure($e, $operationId);
            throw $e;
        } finally {
            $this->monitor->stopOperation($operationId);
        }
    }

    /**
     * Check and manage resource utilization
     */
    private function checkResourceUtilization(): void
    {
        $metrics = [
            'cpu' => $this->getCpuUsage(),
            'memory' => $this->getMemoryUsage(),
            'disk' => $this->getDiskUsage(),
            'network' => $this->getNetworkUsage()
        ];

        foreach ($metrics as $resource => $usage) {
            if ($usage > $this->thresholds['resources'][$resource]) {
                $this->handleResourceThresholdViolation($resource, $usage);
            }

            $this->monitor->recordMetric("resource.$resource", $usage);
        }
    }

    /**
     * Monitor and optimize response times
     */
    private function monitorResponseTimes(): void
    {
        $times = [
            'api' => $this->measureApiResponseTime(),
            'database' => $this->measureDatabaseResponseTime(),
            'cache' => $this->measureCacheResponseTime()
        ];

        foreach ($times as $operation => $time) {
            if ($time > $this->thresholds['response_times'][$operation]) {
                $this->handleSlowResponse($operation, $time);
            }

            $this->monitor->recordMetric("response_time.$operation", $time);
        }
    }

    /**
     * Analyze and optimize cache efficiency
     */
    private function analyzeCacheEfficiency(): void
    {
        $metrics = $this->cache->getEfficiencyMetrics();

        if ($metrics['hit_rate'] < $this->thresholds['cache']['min_hit_rate']) {
            $this->optimizeCacheStrategy($metrics);
        }

        if ($metrics['memory_usage'] > $this->thresholds['cache']['max_memory']) {
            $this->pruneCacheData($metrics);
        }
    }

    /**
     * Monitor and optimize database performance
     */
    private function monitorDatabasePerformance(): void
    {
        $metrics = $this->database->getPerformanceMetrics();

        if ($metrics['query_time'] > $this->thresholds['database']['max_query_time']) {
            $this->optimizeDatabaseQueries();
        }

        if ($metrics['connection_count'] > $this->thresholds['database']['max_connections']) {
            $this->optimizeConnectionPool();
        }
    }

    /**
     * Handle resource threshold violation
     */
    private function handleResourceThresholdViolation(string $resource, float $usage): void
    {
        $this->monitor->triggerAlert('resource_threshold_exceeded', [
            'resource' => $resource,
            'usage' => $usage,
            'threshold' => $this->thresholds['resources'][$resource]
        ]);

        // Execute automatic optimization
        $this->executeResourceOptimization($resource);
    }

    /**
     * Execute resource-specific optimization
     */
    private function executeResourceOptimization(string $resource): void
    {
        switch ($resource) {
            case 'memory':
                $this->optimizeMemoryUsage();
                break;
            case 'cpu':
                $this->optimizeCpuUsage();
                break;
            case 'disk':
                $this->optimizeDiskUsage();
                break;
            case 'network':
                $this->optimizeNetworkUsage();
                break;
        }
    }

    /**
     * Verify system optimizations
     */
    private function verifyOptimizations(): void
    {
        $metrics = $this->monitor->getSystemMetrics();
        
        foreach ($metrics as $metric => $value) {
            if ($value > $this->thresholds[$metric]) {
                throw new PerformanceException(
                    "Optimization verification failed for: $metric"
                );
            }
        }
    }

    /**
     * Handle optimization failure
     */
    private function handleOptimizationFailure(\Throwable $e, string $operationId): void
    {
        $this->monitor->recordMetric('optimization.failure', 1);
        
        $this->monitor->triggerAlert('optimization_failed', [
            'operation_id' => $operationId,
            'error' => $e->getMessage(),
            'metrics' => $this->monitor->getSystemMetrics()
        ]);

        // Roll back optimizations if needed
        $this->rollbackOptimizations();
    }
}
```
