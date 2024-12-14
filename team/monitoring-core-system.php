<?php

namespace App\Core\Monitoring;

use Illuminate\Support\Facades\{Cache, DB, Log, Redis};
use App\Core\Security\SecurityManager;
use App\Core\Services\{ValidationService, NotificationService};
use App\Core\Exceptions\{MonitoringException, SystemException};

class SystemMonitor implements MonitorInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private NotificationService $notifier;
    
    private const CACHE_TTL = 60;
    private const CRITICAL_THRESHOLD = 90;
    private const WARNING_THRESHOLD = 75;
    private const METRICS_RETENTION = 1440; // 24 hours in minutes

    private array $criticalServices = [
        'database',
        'cache',
        'queue',
        'storage',
        'security'
    ];

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        NotificationService $notifier
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->notifier = $notifier;
    }

    public function checkHealth(): array
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeHealthCheck(),
            ['action' => 'monitor.health_check']
        );
    }

    protected function executeHealthCheck(): array
    {
        $status = [];
        
        foreach ($this->criticalServices as $service) {
            try {
                $serviceStatus = $this->checkServiceHealth($service);
                $status[$service] = $serviceStatus;

                if ($serviceStatus['status'] === 'critical') {
                    $this->handleCriticalService($service, $serviceStatus);
                }

            } catch (\Exception $e) {
                Log::error("Health check failed for {$service}", [
                    'error' => $e->getMessage()
                ]);
                
                $status[$service] = [
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'timestamp' => now()
                ];
            }
        }

        $this->recordHealthMetrics($status);
        return $status;
    }

    public function getPerformanceMetrics(): array
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeGetMetrics(),
            ['action' => 'monitor.get_metrics']
        );
    }

    protected function executeGetMetrics(): array
    {
        return [
            'system' => $this->getSystemMetrics(),
            'application' => $this->getApplicationMetrics(),
            'database' => $this->getDatabaseMetrics(),
            'cache' => $this->getCacheMetrics(),
            'security' => $this->getSecurityMetrics()
        ];
    }

    protected function checkServiceHealth(string $service): array
    {
        return match($service) {
            'database' => $this->checkDatabaseHealth(),
            'cache' => $this->checkCacheHealth(),
            'queue' => $this->checkQueueHealth(),
            'storage' => $this->checkStorageHealth(),
            'security' => $this->checkSecurityHealth(),
            default => throw new MonitoringException("Unknown service: {$service}")
        };
    }

    protected function checkDatabaseHealth(): array
    {
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $responseTime = microtime(true) - $start;

            $connections = DB::getConnections();
            $activeConnections = array_filter($connections, function($connection) {
                return $connection->getReadPdo() !== null;
            });

            return [
                'status' => $responseTime < 0.1 ? 'healthy' : 'degraded',
                'response_time' => $responseTime,
                'active_connections' => count($activeConnections),
                'timestamp' => now()
            ];

        } catch (\Exception $e) {
            throw new MonitoringException('Database health check failed: ' . $e->getMessage());
        }
    }

    protected function checkCacheHealth(): array
    {
        try {
            $key = 'health_check_' . time();
            $value = random_bytes(32);
            
            $start = microtime(true);
            Cache::put($key, $value, 10);
            $stored = Cache::get($key) === $value;
            $responseTime = microtime(true) - $start;
            
            Cache::forget($key);

            return [
                'status' => $stored ? 'healthy' : 'error',
                'response_time' => $responseTime,
                'hit_rate' => $this->getCacheHitRate(),
                'timestamp' => now()
            ];

        } catch (\Exception $e) {
            throw new MonitoringException('Cache health check failed: ' . $e->getMessage());
        }
    }

    protected function checkQueueHealth(): array
    {
        try {
            $queueSize = Redis::llen('queues:default');
            $failedJobs = DB::table('failed_jobs')->count();
            
            $status = 'healthy';
            if ($failedJobs > 0) {
                $status = $failedJobs > 10 ? 'critical' : 'warning';
            }

            return [
                'status' => $status,
                'queue_size' => $queueSize,
                'failed_jobs' => $failedJobs,
                'timestamp' => now()
            ];

        } catch (\Exception $e) {
            throw new MonitoringException('Queue health check failed: ' . $e->getMessage());
        }
    }

    protected function checkStorageHealth(): array
    {
        try {
            $disk = disk_free_space('/');
            $total = disk_total_space('/');
            $used = $total - $disk;
            $usedPercentage = ($used / $total) * 100;

            $status = 'healthy';
            if ($usedPercentage > self::CRITICAL_THRESHOLD) {
                $status = 'critical';
            } elseif ($usedPercentage > self::WARNING_THRESHOLD) {
                $status = 'warning';
            }

            return [
                'status' => $status,
                'used_percentage' => $usedPercentage,
                'free_space' => $disk,
                'timestamp' => now()
            ];

        } catch (\Exception $e) {
            throw new MonitoringException('Storage health check failed: ' . $e->getMessage());
        }
    }

    protected function checkSecurityHealth(): array
    {
        try {
            $failedLogins = Cache::get('failed_logins', 0);
            $suspiciousActivities = Cache::get('suspicious_activities', 0);
            
            $status = 'healthy';
            if ($failedLogins > 100 || $suspiciousActivities > 10) {
                $status = 'critical';
            } elseif ($failedLogins > 50 || $suspiciousActivities > 5) {
                $status = 'warning';
            }

            return [
                'status' => $status,
                'failed_logins' => $failedLogins,
                'suspicious_activities' => $suspiciousActivities,
                'timestamp' => now()
            ];

        } catch (\Exception $e) {
            throw new MonitoringException('Security health check failed: ' . $e->getMessage());
        }
    }

    protected function handleCriticalService(string $service, array $status): void
    {
        $this->notifier->sendCriticalAlert([
            'service' => $service,
            'status' => $status,
            'timestamp' => now()
        ]);

        Log::critical("Critical service status: {$service}", $status);

        if ($this->canAutoRecover($service)) {
            $this->attemptRecovery($service);
        }
    }

    protected function canAutoRecover(string $service): bool
    {
        return in_array($service, [
            'cache',
            'queue'
        ]);
    }

    protected function attemptRecovery(string $service): void
    {
        try {
            match($service) {
                'cache' => $this->recoverCache(),
                'queue' => $this->recoverQueue(),
                default => throw new MonitoringException("No recovery procedure for {$service}")
            };
        } catch (\Exception $e) {
            Log::error("Recovery failed for {$service}", [
                'error' => $e->getMessage()
            ]);
        }
    }

    protected function recordHealthMetrics(array $status): void
    {
        $metrics = [
            'timestamp' => now()->timestamp,
            'status' => $status
        ];

        Redis::lpush('health_metrics', json_encode($metrics));
        Redis::ltrim('health_metrics', 0, self::METRICS_RETENTION - 1);
    }

    private function getCacheHitRate(): float
    {
        $hits = Cache::get('cache_hits', 0);
        $misses = Cache::get('cache_misses', 0);
        $total = $hits + $misses;
        
        return $total > 0 ? ($hits / $total) * 100 : 0;
    }
}
