<?php

namespace App\Core\Infrastructure;

use App\Core\Security\SecurityManager;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class InfrastructureManager
{
    private SecurityManager $security;
    private MonitoringService $monitor;
    private CacheManager $cache;
    private LogManager $logger;
    private MetricsCollector $metrics;

    public function __construct(
        SecurityManager $security,
        MonitoringService $monitor,
        CacheManager $cache,
        LogManager $logger,
        MetricsCollector $metrics
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->cache = $cache;
        $this->logger = $logger;
        $this->metrics = $metrics;
    }

    public function initializeInfrastructure(): void
    {
        $this->security->executeCriticalOperation(
            fn() => $this->setupInfrastructureComponents(),
            new SecurityContext('infrastructure_init')
        );
    }

    private function setupInfrastructureComponents(): void
    {
        // Initialize caching system
        $this->initializeCacheSystem();

        // Setup monitoring
        $this->initializeMonitoring();

        // Configure logging
        $this->initializeLogging();

        // Setup metrics collection
        $this->initializeMetrics();
    }

    private function initializeCacheSystem(): void
    {
        $this->cache->initialize([
            'default' => 'redis',
            'stores' => [
                'redis' => [
                    'driver' => 'redis',
                    'connection' => 'cache',
                    'lock_connection' => 'default'
                ],
                'local' => [
                    'driver' => 'file',
                    'path' => storage_path('framework/cache')
                ]
            ],
            'prefix' => 'cms_cache'
        ]);

        // Verify cache connection
        if (!$this->verifyCacheConnection()) {
            throw new InfrastructureException('Cache system initialization failed');
        }
    }

    private function verifyCacheConnection(): bool
    {
        try {
            $testKey = 'cache_test_' . time();
            $testValue = 'test_value';
            
            // Test write
            $this->cache->put($testKey, $testValue, 60);
            
            // Test read
            $result = $this->cache->get($testKey);
            
            // Cleanup
            $this->cache->forget($testKey);
            
            return $result === $testValue;
        } catch (\Exception $e) {
            $this->logger->critical('Cache connection verification failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    private function initializeMonitoring(): void
    {
        $this->monitor->initialize([
            'services' => [
                'web' => [
                    'interval' => 60, // seconds
                    'timeout' => 5,   // seconds
                    'retries' => 3
                ],
                'api' => [
                    'interval' => 30,
                    'timeout' => 3,
                    'retries' => 3
                ],
                'database' => [
                    'interval' => 60,
                    'checks' => ['connection', 'replication']
                ]
            ],
            'alerts' => [
                'channels' => ['slack', 'email'],
                'thresholds' => [
                    'response_time' => 500, // ms
                    'error_rate' => 0.01,   // 1%
                    'memory_usage' => 0.9    // 90%
                ]
            ]
        ]);
    }

    private function initializeLogging(): void
    {
        $this->logger->initialize([
            'channels' => [
                'security' => [
                    'driver' => 'daily',
                    'level' => 'debug',
                    'days' => 14,
                    'permission' => 0600
                ],
                'performance' => [
                    'driver' => 'daily',
                    'level' => 'info',
                    'days' => 7
                ],
                'audit' => [
                    'driver' => 'daily',
                    'level' => 'info',
                    'days' => 30,
                    'permission' => 0600
                ]
            ],
            'encryption' => [
                'sensitive_fields' => ['password', 'token', 'key'],
                'algorithm' => 'AES-256-CBC'
            ]
        ]);
    }

    private function initializeMetrics(): void
    {
        $this->metrics->initialize([
            'collectors' => [
                'performance' => [
                    'response_time',
                    'throughput',
                    'error_rate'
                ],
                'resources' => [
                    'cpu_usage',
                    'memory_usage',
                    'disk_usage'
                ],
                'security' => [
                    'auth_attempts',
                    'failed_logins',
                    'suspicious_activities'
                ]
            ],
            'storage' => [
                'driver' => 'redis',
                'retention' => [
                    'raw' => '24h',
                    'aggregated' => '30d'
                ]
            ],
            'aggregation' => [
                'intervals' => ['1m', '5m', '1h', '1d'],
                'functions' => ['avg', 'max', 'min', 'count']
            ]
        ]);
    }

    public function monitorSystemHealth(): HealthStatus
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->checkSystemHealth(),
            new SecurityContext('health_check')
        );
    }

    private function checkSystemHealth(): HealthStatus
    {
        $status = new HealthStatus();

        // Check cache health
        $status->addCheck('cache', $this->checkCacheHealth());

        // Check database health
        $status->addCheck('database', $this->checkDatabaseHealth());

        // Check storage health
        $status->addCheck('storage', $this->checkStorageHealth());

        // Check security status
        $status->addCheck('security', $this->checkSecurityHealth());

        return $status;
    }

    private function checkCacheHealth(): HealthCheck
    {
        $check = new HealthCheck('cache');

        try {
            $start = microtime(true);
            if ($this->verifyCacheConnection()) {
                $latency = (microtime(true) - $start) * 1000;
                $check->setStatus('healthy')
                      ->setLatency($latency)
                      ->setMetrics($this->cache->getMetrics());
            } else {
                $check->setStatus('unhealthy')
                      ->setError('Cache verification failed');
            }
        } catch (\Exception $e) {
            $check->setStatus('unhealthy')
                  ->setError($e->getMessage());
        }

        return $check;
    }

    private function checkDatabaseHealth(): HealthCheck
    {
        $check = new HealthCheck('database');
        
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $latency = (microtime(true) - $start) * 1000;
            
            $check->setStatus('healthy')
                  ->setLatency($latency)
                  ->setMetrics(DB::getMetrics());
                  
        } catch (\Exception $e) {
            $check->setStatus('unhealthy')
                  ->setError($e->getMessage());
        }

        return $check;
    }

    private function checkStorageHealth(): HealthCheck
    {
        $check = new HealthCheck('storage');
        
        try {
            $diskUsage = disk_free_space('/') / disk_total_space('/');
            $status = $diskUsage > 0.1 ? 'healthy' : 'warning';
            
            $check->setStatus($status)
                  ->setMetrics(['disk_usage' => $diskUsage]);
                  
        } catch (\Exception $e) {
            $check->setStatus('unhealthy')
                  ->setError($e->getMessage());
        }

        return $check;
    }

    private function checkSecurityHealth(): HealthCheck
    {
        return $this->security->performHealthCheck();
    }
}
