<?php
namespace App\Core\Monitoring;

class SystemMonitor {
    private MetricsCollector $metrics;
    private ThresholdManager $thresholds;
    private AlertSystem $alerts;
    private LogManager $logger;

    public function monitorSystemHealth(): void {
        $metrics = $this->collectSystemMetrics();
        
        try {
            $this->validateMetrics($metrics);
            $this->storeMetrics($metrics);
            $this->updateDashboard($metrics);
            
        } catch (\Exception $e) {
            $this->handleMonitoringFailure($e, $metrics);
            throw $e;
        }
    }

    private function collectSystemMetrics(): array {
        return [
            'cpu_usage' => sys_getloadavg()[0],
            'memory_usage' => memory_get_usage(true),
            'disk_usage' => disk_free_space('/'),
            'response_time' => $this->metrics->getAverageResponseTime(),
            'error_rate' => $this->metrics->getErrorRate(),
            'active_users' => $this->metrics->getActiveUsers(),
            'timestamp' => microtime(true)
        ];
    }

    private function validateMetrics(array $metrics): void {
        foreach ($metrics as $metric => $value) {
            $threshold = $this->thresholds->get($metric);
            
            if ($this->isThresholdExceeded($value, $threshold)) {
                $this->alerts->notifyThresholdExceeded($metric, $value, $threshold);
            }
        }
    }

    private function isThresholdExceeded($value, $threshold): bool {
        return isset($threshold) && $value > $threshold;
    }

    private function storeMetrics(array $metrics): void {
        $this->metrics->store('system_health', $metrics);
    }
}

class PerformanceMonitor {
    private MetricsCollector $metrics;
    private CacheManager $cache;
    private ConfigManager $config;

    public function trackOperation(string $operation, callable $callback): mixed {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        
        try {
            $result = $callback();
            
            $this->recordOperationMetrics($operation, [
                'execution_time' => microtime(true) - $startTime,
                'memory_usage' => memory_get_usage(true) - $startMemory
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->recordOperationFailure($operation, $e);
            throw $e;
        }
    }

    private function recordOperationMetrics(string $operation, array $metrics): void {
        $this->metrics->record($operation, array_merge($metrics, [
            'timestamp' => microtime(true),
            'server_id' => gethostname()
        ]));

        $this->updateOperationCache($operation, $metrics);
    }

    private function updateOperationCache(string $operation, array $metrics): void {
        $key = "metrics:operation:$operation";
        $ttl = $this->config->get('metrics.cache_ttl', 3600);
        
        $this->cache->remember($key, $ttl, function() use ($metrics) {
            return $metrics;
        });
    }
}

interface LogManager {
    public function logMetrics(array $metrics): void;
    public function logFailure(\Exception $e, array $context): void;
}

interface ConfigManager {
    public function get(string $key, $default = null): mixed;
}

interface CacheManager {
    public function remember(string $key, int $ttl, callable $callback): mixed;
}

class MonitoringException extends \Exception {}
