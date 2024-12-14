<?php

namespace App\Core\Infrastructure;

use Illuminate\Support\Facades\{Redis, Log, DB};
use Psr\Log\LoggerInterface;

class InfrastructureManager implements InfrastructureInterface
{
    private CacheManager $cache;
    private LogManager $logger;
    private MonitoringService $monitor;
    private HealthCheck $health;

    public function __construct(
        CacheManager $cache,
        LogManager $logger,
        MonitoringService $monitor,
        HealthCheck $health
    ) {
        $this->cache = $cache;
        $this->logger = $logger;
        $this->monitor = $monitor;
        $this->health = $health;
    }

    public function initializeInfrastructure(): void
    {
        try {
            // Initialize core services
            $this->initializeServices();
            
            // Set up monitoring
            $this->setupMonitoring();
            
            // Configure caching
            $this->configureCaching();
            
            // Verify system health
            $this->verifySystemHealth();
            
        } catch (\Exception $e) {
            $this->logger->critical('Infrastructure initialization failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function initializeServices(): void
    {
        // Configure Redis for cache/queue
        Redis::setOptions([
            'prefix' => config('app.name') . ':',
            'serializer' => Redis::SERIALIZER_IGBINARY,
            'compression' => true
        ]);

        // Configure database
        DB::statement('SET SESSION sql_mode = ?', [
            'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'
        ]);

        // Initialize queue workers
        $this->initializeQueueWorkers();
    }

    private function setupMonitoring(): void
    {
        // Configure performance monitoring
        $this->monitor->setupPerformanceMonitoring([
            'slow_query_threshold' => 100, // ms
            'memory_threshold' => 128 * 1024 * 1024, // 128MB
            'cpu_threshold' => 80 // 80% CPU usage
        ]);

        // Configure error tracking
        $this->monitor->setupErrorTracking([
            'capture_stack_traces' => true,
            'ignored_exceptions' => [
                ValidationException::class
            ]
        ]);

        // Set up real-time metrics
        $this->monitor->setupMetrics([
            'interval' => 60, // 60 seconds
            'retention' => 24 * 60 * 60 // 24 hours
        ]);
    }

    private function configureCaching(): void
    {
        // Configure cache tags
        $this->cache->setTagPrefix(config('app.name') . ':tags:');

        // Set up cache garbage collection
        $this->cache->setupGarbageCollection([
            'probability' => 100,
            'divisor' => 100000
        ]);

        // Configure cache drivers
        $this->cache->configureDrivers([
            'redis' => [
                'compression' => true,
                'connection' => 'cache'
            ]
        ]);
    }

    private function verifySystemHealth(): void
    {
        $healthCheck = $this->health->runFullCheck();
        
        if (!$healthCheck->isHealthy()) {
            throw new InfrastructureException(
                'System health check failed: ' . $healthCheck->getFailureReason()
            );
        }
    }

    public function monitorSystem(): array
    {
        return [
            'cache' => $this->monitorCache(),
            'database' => $this->monitorDatabase(),
            'queue' => $this->monitorQueue(),
            'system' => $this->monitorSystemResources()
        ];
    }

    private function monitorCache(): array
    {
        return [
            'hit_rate' => $this->cache->getHitRate(),
            'memory_usage' => $this->cache->getMemoryUsage(),
            'keys_count' => $this->cache->getKeysCount()
        ];
    }

    private function monitorDatabase(): array
    {
        return [
            'connections' => DB::getConnections(),
            'slow_queries' => $this->monitor->getSlowQueries(),
            'query_count' => $this->monitor->getQueryCount()
        ];
    }

    private function monitorQueue(): array
    {
        return [
            'jobs_pending' => $this->monitor->getPendingJobs(),
            'jobs_failed' => $this->monitor->getFailedJobs(),
            'throughput' => $this->monitor->getQueueThroughput()
        ];
    }

    private function monitorSystemResources(): array
    {
        return [
            'cpu_usage' => $this->monitor->getCpuUsage(),
            'memory_usage' => $this->monitor->getMemoryUsage(),
            'disk_usage' => $this->monitor->getDiskUsage()
        ];
    }

    public function scaleResources(string $resource, int $amount): void
    {
        try {
            switch ($resource) {
                case 'workers':
                    $this->scaleQueueWorkers($amount);
                    break;
                case 'cache':
                    $this->scaleCacheSize($amount);
                    break;
                case 'database':
                    $this->scaleDatabaseConnections($amount);
                    break;
                default:
                    throw new \InvalidArgumentException("Unknown resource: {$resource}");
            }
        } catch (\Exception $e) {
            $this->logger->error("Failed to scale {$resource}", [
                'amount' => $amount,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function initializeQueueWorkers(): void
    {
        $queues = [
            'high' => 5,    // 5 workers
            'default' => 3,  // 3 workers
            'low' => 1      // 1 worker
        ];

        foreach ($queues as $queue => $workers) {
            $this->startQueueWorkers($queue, $workers);
        }
    }

    private function startQueueWorkers(string $queue, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            dispatch(new StartQueueWorker($queue));
        }
    }
}
