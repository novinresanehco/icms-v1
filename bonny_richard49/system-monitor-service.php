<?php

namespace App\Core\System;

use App\Core\Interfaces\{
    MonitoringInterface,
    CacheManagerInterface,
    AlertServiceInterface
};
use App\Core\Security\SecurityContext;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;

class SystemMonitorService implements MonitoringInterface 
{
    private CacheManagerInterface $cache;
    private AlertServiceInterface $alerts;
    private LoggerInterface $logger;

    private const CACHE_TTL = 60;
    private const CRITICAL_CPU = 80;
    private const CRITICAL_MEMORY = 85;
    private const CRITICAL_DISK = 90;
    private const ERROR_THRESHOLD = 50;

    public function __construct(
        CacheManagerInterface $cache,
        AlertServiceInterface $alerts,
        LoggerInterface $logger
    ) {
        $this->cache = $cache;
        $this->alerts = $alerts;
        $this->logger = $logger;
    }

    public function monitorSystemHealth(): SystemHealth
    {
        return $this->cache->remember('system.health', self::CACHE_TTL, function() {
            $health = new SystemHealth();
            
            // Monitor system resources
            $health->setCpuUsage($this->monitorCpuUsage());
            $health->setMemoryUsage($this->monitorMemoryUsage());
            $health->setDiskUsage($this->monitorDiskUsage());
            
            // Monitor performance
            $health->setResponseTimes($this->monitorResponseTimes());
            $health->setErrorRates($this->monitorErrorRates());
            $health->setActiveUsers($this->monitorActiveUsers());

            // Check critical services
            $health->setServiceStatus($this->checkCriticalServices());
            
            // Trigger alerts if needed
            $this->checkHealthAlerts($health);
            
            return $health;
        });
    }

    public function monitorSecurityEvents(SecurityContext $context): void
    {
        try {
            $events = $this->getSecurityEvents();
            
            foreach ($events as $event) {
                if ($this->isSecurityThreat($event)) {
                    $this->handleSecurityThreat($event, $context);
                }
            }

            $this->logSecurityStatus($events, $context);
        } catch (\Exception $e) {
            $this->handleMonitoringError($e, 'Security event monitoring failed');
        }
    }

    public function monitorPerformance(): PerformanceMetrics
    {
        return $this->cache->remember('system.performance', self::CACHE_TTL, function() {
            $metrics = new PerformanceMetrics();

            // Monitor database performance
            $metrics->setDbMetrics($this->monitorDatabase());
            
            // Monitor cache performance
            $metrics->setCacheMetrics($this->monitorCache());
            
            // Monitor API performance
            $metrics->setApiMetrics($this->monitorApi());
            
            // Check for performance issues
            $this->checkPerformanceAlerts($metrics);
            
            return $metrics;
        });
    }

    protected function monitorCpuUsage(): float
    {
        $usage = sys_getloadavg()[0] * 100;
        
        if ($usage > self::CRITICAL_CPU) {
            $this->alerts->triggerAlert('high_cpu_usage', [
                'usage' => $usage,
                'threshold' => self::CRITICAL_CPU
            ]);
        }
        
        return $usage;
    }

    protected function monitorMemoryUsage(): float
    {
        $usage = memory_get_usage(true) / memory_get_peak_usage(true) * 100;
        
        if ($usage > self::CRITICAL_MEMORY) {
            $this->alerts->triggerAlert('high_memory_usage', [
                'usage' => $usage,
                'threshold' => self::CRITICAL_MEMORY
            ]);
        }
        
        return $usage;
    }

    protected function monitorDiskUsage(): float
    {
        $total = disk_total_space('/');
        $free = disk_free_space('/');
        $usage = ($total - $free) / $total * 100;
        
        if ($usage > self::CRITICAL_DISK) {
            $this->alerts->triggerAlert('high_disk_usage', [
                'usage' => $usage,
                'threshold' => self::CRITICAL_DISK
            ]);
        }
        
        return $usage;
    }

    protected function monitorResponseTimes(): array
    {
        return DB::table('response_times')
            ->where('created_at', '>', time() - 3600)
            ->selectRaw('avg(time) as average, max(time) as maximum')
            ->first();
    }

    protected function monitorErrorRates(): array
    {
        $errors = DB::table('error_logs')
            ->where('created_at', '>', time() - 3600)
            ->count();
            
        if ($errors > self::ERROR_THRESHOLD) {
            $this->alerts->triggerAlert('high_error_rate', [
                'count' => $errors,
                'threshold' => self::ERROR_THRESHOLD
            ]);
        }
        
        return [
            'count' => $errors,
            'rate' => $errors / 3600
        ];
    }

    protected function monitorActiveUsers(): int
    {
        return DB::table('sessions')
            ->where('last_activity', '>', time() - 300)
            ->count();
    }

    protected function checkCriticalServices(): array
    {
        $services = config('monitoring.critical_services');
        $status = [];
        
        foreach ($services as $service) {
            $status[$service] = $this->checkServiceHealth($service);
        }
        
        return $status;
    }

    protected function checkServiceHealth(string $service): bool
    {
        try {
            return app($service)->isHealthy();
        } catch (\Exception $e) {
            $this->handleMonitoringError($e, "Service health check failed: {$service}");
            return false;
        }
    }

    protected function monitorDatabase(): array
    {
        return [
            'connections' => DB::table('information_schema.processlist')->count(),
            'slow_queries' => DB::table('mysql.slow_log')
                ->where('start_time', '>', now()->subHour())
                ->count(),
            'deadlocks' => $this->getDeadlockCount()
        ];
    }

    protected function monitorCache(): array
    {
        return [
            'hit_rate' => $this->cache->getHitRate(),
            'memory_usage' => $this->cache->getMemoryUsage(),
            'keys_count' => $this->cache->getKeysCount()
        ];
    }

    protected function monitorApi(): array
    {
        return [
            'response_times' => $this->getApiResponseTimes(),
            'error_rates' => $this->getApiErrorRates(),
            'request_count' => $this->getApiRequestCount()
        ];
    }

    protected function getDeadlockCount(): int
    {
        return DB::table('information_schema.innodb_metrics')
            ->where('name', 'lock_deadlocks')
            ->value('count');
    }

    protected function getApiResponseTimes(): array
    {
        return DB::table('api_metrics')
            ->where('created_at', '>', now()->subHour())
            ->selectRaw('
                avg(response_time) as average,
                max(response_time) as maximum,
                min(response_time) as minimum
            ')
            ->first();
    }

    protected function getApiErrorRates(): array
    {
        $total = DB::table('api_requests')
            ->where('created_at', '>', now()->subHour())
            ->count();
            
        $errors = DB::table('api_errors')
            ->where('created_at', '>', now()->subHour())
            ->count();
            
        return [
            'total' => $total,
            'errors' => $errors,
            'rate' => $total ? ($errors / $total) * 100 : 0
        ];
    }

    protected function getApiRequestCount(): int
    {
        return DB::table('api_requests')
            ->where('created_at', '>', now()->subHour())
            ->count();
    }

    protected function handleMonitoringError(\Exception $e, string $message): void
    {
        $this->logger->error($message, [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        $this->alerts->triggerAlert('monitoring_error', [
            'message' => $message,
            'error' => $e->getMessage()
        ]);
    }
}
