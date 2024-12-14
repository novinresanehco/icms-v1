<?php

namespace App\Core\Infrastructure;

use Illuminate\Support\Facades\{Cache, Log, DB};
use App\Core\Infrastructure\Monitoring\PerformanceMonitor;
use App\Core\Infrastructure\Caching\CacheManager;
use App\Core\Infrastructure\Exceptions\InfrastructureException;

/**
 * Core Infrastructure System
 * Handles caching, monitoring, logging and system health
 */
class InfrastructureManager implements InfrastructureInterface
{
    private PerformanceMonitor $monitor;
    private CacheManager $cache;
    private SecurityManager $security;
    private LogManager $logger;
    private MetricsCollector $metrics;

    public function __construct(
        PerformanceMonitor $monitor,
        CacheManager $cache,
        SecurityManager $security,
        LogManager $logger,
        MetricsCollector $metrics
    ) {
        $this->monitor = $monitor;
        $this->cache = $cache;
        $this->security = $security;
        $this->logger = $logger;
        $this->metrics = $metrics;
    }

    /**
     * Initialize infrastructure with monitoring
     */
    public function initialize(): void
    {
        try {
            // Start performance monitoring
            $this->monitor->start();

            // Initialize cache systems
            $this->cache->initialize();

            // Start metrics collection
            $this->metrics->startCollection();

            // Log successful initialization
            $this->logger->info('Infrastructure initialized successfully', [
                'cache_status' => $this->cache->getStatus(),
                'monitor_status' => $this->monitor->getStatus(),
                'metrics_status' => $this->metrics->getStatus()
            ]);

        } catch (\Exception $e) {
            $this->handleInitializationFailure($e);
        }
    }

    /**
     * Monitor system health with metrics
     */
    public function monitorHealth(): HealthStatus
    {
        try {
            // Collect system metrics
            $metrics = [
                'memory_usage' => memory_get_usage(true),
                'cpu_load' => sys_getloadavg(),
                'db_connections' => DB::getConnectionsStatus(),
                'cache_hits' => $this->cache->getHitRate(),
                'request_rate' => $this->monitor->getRequestRate()
            ];

            // Check against thresholds
            $issues = $this->checkHealthThresholds($metrics);

            // Log health status
            if (!empty($issues)) {
                $this->logger->warning('Health check identified issues', [
                    'issues' => $issues,
                    'metrics' => $metrics
                ]);
            }

            return new HealthStatus($metrics, $issues);

        } catch (\Exception $e) {
            $this->handleMonitoringFailure($e);
            throw new InfrastructureException(
                'Health monitoring failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Optimize system performance
     */
    public function optimizePerformance(): OptimizationResult
    {
        try {
            // Get current metrics
            $beforeMetrics = $this->monitor->getCurrentMetrics();

            // Optimize cache
            $this->cache->optimize();

            // Optimize database
            $this->optimizeDatabase();

            // Clear unnecessary resources
            $this->clearStaleResources();

            // Get metrics after optimization
            $afterMetrics = $this->monitor->getCurrentMetrics();

            return new OptimizationResult($beforeMetrics, $afterMetrics);

        } catch (\Exception $e) {
            $this->handleOptimizationFailure($e);
            throw new InfrastructureException(
                'Performance optimization failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Manage system resources
     */
    public function manageResources(): ResourceStatus
    {
        try {
            // Check resource usage
            $usage = [
                'memory' => $this->monitor->getMemoryUsage(),
                'cpu' => $this->monitor->getCpuUsage(),
                'disk' => $this->monitor->getDiskUsage(),
                'connections' => $this->monitor->getConnectionCount()
            ];

            // Apply resource limits
            foreach ($usage as $resource => $value) {
                if ($value > config("infrastructure.limits.$resource")) {
                    $this->applyResourceLimit($resource);
                }
            }

            return new ResourceStatus($usage);

        } catch (\Exception $e) {
            $this->handleResourceFailure($e);
            throw new InfrastructureException(
                'Resource management failed: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Optimize database performance
     */
    private function optimizeDatabase(): void
    {
        // Analyze slow queries
        $slowQueries = DB::select("SELECT * FROM mysql.slow_log");
        
        foreach ($slowQueries as $query) {
            $this->logger->warning('Slow query detected', [
                'query' => $query->sql_text,
                'duration' => $query->query_time
            ]);
        }

        // Optimize tables
        DB::statement('OPTIMIZE TABLE users, content, templates');

        // Clear query cache if needed
        if ($this->monitor->getQueryCacheSize() > config('infrastructure.query_cache_limit')) {
            DB::statement('FLUSH QUERY CACHE');
        }
    }

    /**
     * Clear stale system resources
     */
    private function clearStaleResources(): void
    {
        // Clear old sessions
        DB::table('sessions')
            ->where('last_activity', '<', now()->subHours(24))
            ->delete();

        // Clear old cache entries
        $this->cache->clearStale();

        // Clear old logs
        $this->logger->clearOldLogs();

        // Clear temporary files
        $this->clearTempFiles();
    }

    /**
     * Apply resource limits when thresholds are exceeded
     */
    private function applyResourceLimit(string $resource): void
    {
        switch ($resource) {
            case 'memory':
                $this->cache->reduceMemoryUsage();
                break;

            case 'cpu':
                $this->throttleRequests();
                break;

            case 'disk':
                $this->cleanupDiskSpace();
                break;

            case 'connections':
                $this->limitConnections();
                break;
        }

        $this->logger->warning("Resource limit applied for $resource");
    }

    /**
     * Handle initialization failure
     */
    private function handleInitializationFailure(\Exception $e): void
    {
        $this->logger->error('Infrastructure initialization failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        throw new InfrastructureException(
            'Infrastructure initialization failed: ' . $e->getMessage(),
            $e->getCode(),
            $e
        );
    }

    /**
     * Handle monitoring failure
     */
    private function handleMonitoringFailure(\Exception $e): void
    {
        $this->logger->error('Health monitoring failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        // Try to collect basic metrics
        $basicMetrics = [
            'memory_usage' => memory_get_usage(true),
            'load_avg' => sys_getloadavg()
        ];

        $this->metrics->recordFailure('health_monitoring', $basicMetrics);
    }

    /**
     * Check system metrics against defined thresholds
     */
    private function checkHealthThresholds(array $metrics): array
    {
        $issues = [];

        $thresholds = config('infrastructure.health_thresholds');

        foreach ($thresholds as $metric => $threshold) {
            if (isset($metrics[$metric]) && $metrics[$metric] > $threshold) {
                $issues[] = [
                    'metric' => $metric,
                    'current' => $metrics[$metric],
                    'threshold' => $threshold
                ];
            }
        }

        return $issues;
    }
}
