<?php

namespace App\Core\Infrastructure;

use Illuminate\Support\Facades\{Cache, DB, Log, Redis};
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

class InfrastructureManager
{
    private SecurityManager $security;
    private LoggerInterface $logger;
    private MetricsCollector $metrics;
    private array $config;

    public function __construct(
        SecurityManager $security,
        LoggerInterface $logger,
        MetricsCollector $metrics,
        array $config
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function monitorSystemHealth(): SystemHealth
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->collectHealthMetrics(),
            ['action' => 'health_check']
        );
    }

    private function collectHealthMetrics(): SystemHealth
    {
        return new SystemHealth([
            'database' => $this->checkDatabaseHealth(),
            'cache' => $this->checkCacheHealth(),
            'storage' => $this->checkStorageHealth(),
            'queue' => $this->checkQueueHealth(),
            'memory' => $this->checkMemoryUsage(),
            'cpu' => $this->checkCpuUsage()
        ]);
    }

    private function checkDatabaseHealth(): HealthStatus
    {
        try {
            $startTime = microtime(true);
            DB::select('SELECT 1');
            $responseTime = microtime(true) - $startTime;

            $metrics = [
                'connections' => DB::connection()->select('SHOW STATUS LIKE "Threads_connected"')[0]->Value,
                'slow_queries' => DB::connection()->select('SHOW GLOBAL STATUS LIKE "Slow_queries"')[0]->Value,
                'response_time' => $responseTime
            ];

            return new HealthStatus(
                status: $responseTime < 0.1 ? 'healthy' : 'degraded',
                metrics: $metrics
            );
        } catch (\Exception $e) {
            $this->logCriticalIssue('Database health check failed', $e);
            return new HealthStatus(status: 'critical', error: $e->getMessage());
        }
    }

    private function checkCacheHealth(): HealthStatus
    {
        try {
            $key = 'health_check_' . uniqid();
            $value = uniqid();

            Cache::put($key, $value, 10);
            $retrieved = Cache::get($key);
            Cache::forget($key);

            $hitRate = $this->calculateCacheHitRate();

            return new HealthStatus(
                status: $hitRate > 80 ? 'healthy' : 'degraded',
                metrics: [
                    'hit_rate' => $hitRate,
                    'memory_usage' => Cache::getMemoryUsage(),
                    'keys_count' => Cache::getKeyCount()
                ]
            );
        } catch (\Exception $e) {
            $this->logCriticalIssue('Cache health check failed', $e);
            return new HealthStatus(status: 'critical', error: $e->getMessage());
        }
    }

    private function checkStorageHealth(): HealthStatus
    {
        try {
            $disk = storage_path();
            $total = disk_total_space($disk);
            $free = disk_free_space($disk);
            $used = $total - $free;
            $usedPercentage = ($used / $total) * 100;

            return new HealthStatus(
                status: $usedPercentage < 80 ? 'healthy' : 'degraded',
                metrics: [
                    'total_space' => $total,
                    'used_space' => $used,
                    'free_space' => $free,
                    'used_percentage' => $usedPercentage
                ]
            );
        } catch (\Exception $e) {
            $this->logCriticalIssue('Storage health check failed', $e);
            return new HealthStatus(status: 'critical', error: $e->getMessage());
        }
    }

    private function checkQueueHealth(): HealthStatus
    {
        try {
            $queueSize = Redis::llen('queues:default');
            $failedJobs = DB::table('failed_jobs')->count();
            
            return new HealthStatus(
                status: $failedJobs === 0 ? 'healthy' : 'degraded',
                metrics: [
                    'queue_size' => $queueSize,
                    'failed_jobs' => $failedJobs,
                    'processing_rate' => $this->calculateQueueProcessingRate()
                ]
            );
        } catch (\Exception $e) {
            $this->logCriticalIssue('Queue health check failed', $e);
            return new HealthStatus(status: 'critical', error: $e->getMessage());
        }
    }

    public function optimizeSystem(): void
    {
        $this->security->executeCriticalOperation(
            fn() => $this->performOptimization(),
            ['action' => 'system_optimization']
        );
    }

    private function performOptimization(): void
    {
        // Database optimization
        $this->optimizeDatabase();

        // Cache optimization
        $this->optimizeCache();

        // Queue optimization
        $this->optimizeQueue();

        // Storage optimization
        $this->optimizeStorage();
    }

    private function optimizeDatabase(): void
    {
        DB::transaction(function() {
            // Analyze and optimize tables
            foreach ($this->getDatabaseTables() as $table) {
                DB::statement("ANALYZE TABLE $table");
                DB::statement("OPTIMIZE TABLE $table");
            }

            // Clean up old records
            $this->cleanupOldRecords();
        });
    }

    private function optimizeCache(): void
    {
        // Clear expired items
        Cache::tags(['expired'])->flush();

        // Preload frequently accessed data
        $this->preloadCacheData();

        // Adjust cache configuration based on usage patterns
        $this->adjustCacheConfig();
    }

    private function optimizeQueue(): void
    {
        // Clear stuck jobs
        $this->clearStuckJobs();

        // Rebalance queue workers
        $this->rebalanceQueueWorkers();

        // Retry failed jobs
        $this->retryFailedJobs();
    }

    public function handleCriticalError(\Throwable $e): void
    {
        try {
            // Log error details
            $this->logCriticalIssue('Critical system error', $e);

            // Collect system state
            $systemState = $this->collectSystemState();

            // Execute recovery procedures
            $this->executeRecoveryProcedures($e, $systemState);

            // Notify administrators
            $this->notifyAdministrators($e, $systemState);

        } catch (\Exception $fallbackError) {
            // Last resort error handling
            Log::emergency('Critical error handler failed', [
                'original_error' => $e->getMessage(),
                'fallback_error' => $fallbackError->getMessage()
            ]);
        }
    }

    private function logCriticalIssue(string $message, \Throwable $e): void
    {
        $this->logger->critical($message, [
            'exception' => $e,
            'trace' => $e->getTraceAsString(),
            'system_state' => $this->collectSystemState()
        ]);
    }

    private function collectSystemState(): array
    {
        return [
            'memory' => $this->checkMemoryUsage(),
            'cpu' => $this->checkCpuUsage(),
            'disk' => $this->checkStorageHealth(),
            'load' => sys_getloadavg(),
            'time' => now()->toDateTimeString()
        ];
    }

    private function executeRecoveryProcedures(\Throwable $e, array $systemState): void
    {
        // Implement system recovery based on error type and system state
        match (get_class($e)) {
            DatabaseException::class => $this->handleDatabaseFailure(),
            CacheException::class => $this->handleCacheFailure(),
            StorageException::class => $this->handleStorageFailure(),
            default => $this->handleGeneralFailure()
        };
    }
}
