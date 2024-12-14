<?php

namespace App\Core\Infrastructure;

use Illuminate\Support\Facades\{Cache, Log, DB};
use App\Core\Security\SecurityManager;
use App\Core\Infrastructure\Exceptions\{SystemException, PerformanceException};

class InfrastructureManager
{
    private SecurityManager $security;
    private PerformanceMonitor $monitor;
    private CacheManager $cache;
    private array $config;

    public function __construct(
        SecurityManager $security,
        PerformanceMonitor $monitor,
        CacheManager $cache
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->cache = $cache;
        $this->config = config('infrastructure');
        $this->initializeInfrastructure();
    }

    public function initializeInfrastructure(): void
    {
        // Critical system checks
        $this->verifySystemRequirements();
        $this->initializeSecurityLayer();
        $this->setupCacheSystem();
        $this->configurePerformanceMonitoring();
    }

    private function verifySystemRequirements(): void
    {
        // Check PHP version and extensions
        if (version_compare(PHP_VERSION, '8.1.0', '<')) {
            throw new SystemException('PHP 8.1+ required');
        }

        // Verify required extensions
        $required = ['pdo', 'mbstring', 'openssl', 'fileinfo'];
        foreach ($required as $ext) {
            if (!extension_loaded($ext)) {
                throw new SystemException("Required extension missing: {$ext}");
            }
        }

        // Verify file permissions
        $paths = ['storage', 'bootstrap/cache'];
        foreach ($paths as $path) {
            if (!is_writable(base_path($path))) {
                throw new SystemException("Path not writable: {$path}");
            }
        }
    }

    public function monitorSystemHealth(): HealthStatus
    {
        return $this->security->executeCriticalOperation(
            new MonitorHealthOperation(),
            function() {
                $status = new HealthStatus();

                // Check database connectivity
                $status->database = $this->checkDatabaseHealth();

                // Check cache system
                $status->cache = $this->checkCacheHealth();

                // Check file system
                $status->storage = $this->checkStorageHealth();

                // Check memory usage
                $status->memory = $this->checkMemoryHealth();

                // Check queue system
                $status->queue = $this->checkQueueHealth();

                return $status;
            }
        );
    }

    public function optimizeSystem(): void
    {
        $this->security->executeCriticalOperation(
            new OptimizeSystemOperation(),
            function() {
                // Optimize database
                $this->optimizeDatabase();

                // Clear expired cache
                $this->cleanCache();

                // Clean temporary files
                $this->cleanTempFiles();

                // Optimize file storage
                $this->optimizeStorage();
            }
        );
    }

    private function checkDatabaseHealth(): HealthMetrics
    {
        $metrics = new HealthMetrics();
        
        try {
            // Check connection
            DB::connection()->getPdo();
            $metrics->status = 'healthy';

            // Check performance
            $start = microtime(true);
            DB::select('SELECT 1');
            $queryTime = microtime(true) - $start;

            if ($queryTime > 0.1) {
                throw new PerformanceException('Database performance degraded');
            }

            // Check connections count
            $activeConnections = DB::select('SHOW STATUS LIKE "Threads_connected"');
            if ($activeConnections[0]->Value > $this->config['max_connections']) {
                throw new PerformanceException('Too many database connections');
            }

        } catch (\Exception $e) {
            $metrics->status = 'unhealthy';
            $metrics->error = $e->getMessage();
        }

        return $metrics;
    }

    private function checkCacheHealth(): HealthMetrics
    {
        $metrics = new HealthMetrics();
        
        try {
            // Write test
            $testKey = 'health_check_' . time();
            Cache::put($testKey, 'test', 1);
            
            // Read test
            if (Cache::get($testKey) !== 'test') {
                throw new SystemException('Cache read/write test failed');
            }

            // Check hit rate
            $hitRate = $this->cache->getHitRate();
            if ($hitRate < $this->config['min_cache_hit_rate']) {
                throw new PerformanceException('Cache hit rate below threshold');
            }

            $metrics->status = 'healthy';
            
        } catch (\Exception $e) {
            $metrics->status = 'unhealthy';
            $metrics->error = $e->getMessage();
        }

        return $metrics;
    }

    private function checkStorageHealth(): HealthMetrics
    {
        $metrics = new HealthMetrics();
        
        try {
            // Check disk space
            $freeSpace = disk_free_space(storage_path());
            $totalSpace = disk_total_space(storage_path());
            $usedPercentage = (($totalSpace - $freeSpace) / $totalSpace) * 100;

            if ($usedPercentage > $this->config['max_storage_usage']) {
                throw new SystemException('Storage space critical');
            }

            // Check write permissions
            $testFile = storage_path('health_check.tmp');
            if (!file_put_contents($testFile, 'test')) {
                throw new SystemException('Storage write test failed');
            }
            unlink($testFile);

            $metrics->status = 'healthy';
            
        } catch (\Exception $e) {
            $metrics->status = 'unhealthy';
            $metrics->error = $e->getMessage();
        }

        return $metrics;
    }

    private function optimizeDatabase(): void
    {
        // Analyze tables
        DB::unprepared('ANALYZE TABLE ' . implode(',', $this->getDatabaseTables()));

        // Optimize indexes
        foreach ($this->getDatabaseTables() as $table) {
            DB::unprepared("OPTIMIZE TABLE {$table}");
        }

        // Update statistics
        DB::unprepared('FLUSH STATUS');
    }

    private function cleanCache(): void
    {
        // Clear expired items
        $this->cache->cleanup();

        // Clear old view cache
        $this->clearViewCache();

        // Rebuild route cache
        $this->rebuildRouteCache();
    }

    private function optimizeStorage(): void
    {
        // Clean old temporary files
        $this->cleanTempFiles();

        // Optimize media storage
        $this->optimizeMediaStorage();

        // Check and repair file permissions
        $this->repairFilePermissions();
    }

    public function backupSystem(): void
    {
        $this->security->executeCriticalOperation(
            new BackupSystemOperation(),
            function() {
                // Backup database
                $this->backupDatabase();

                // Backup files
                $this->backupFiles();

                // Verify backup integrity
                $this->verifyBackup();
            }
        );
    }

    private function configurePerformanceMonitoring(): void
    {
        $this->monitor->setThresholds([
            'response_time' => $this->config['max_response_time'],
            'memory_usage' => $this->config['max_memory_usage'],
            'cpu_usage' => $this->config['max_cpu_usage']
        ]);

        $this->monitor->startMonitoring();
    }

    public function handleSystemFailure(\Throwable $e): void
    {
        Log::critical('System failure detected', [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'system_state' => $this->captureSystemState()
        ]);

        // Attempt recovery
        $this->executeFailureRecovery();

        // Notify administrators
        $this->notifySystemAdministrators($e);
    }
}
