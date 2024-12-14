<?php

namespace App\Core\Monitoring;

use App\Core\Security\SecurityManager;
use App\Core\Events\MonitoringEvent;
use App\Core\Exceptions\MonitoringException;
use Illuminate\Support\Facades\{DB, Log, Cache};

class MonitoringSystem implements MonitoringInterface
{
    private SecurityManager $security;
    private array $metrics = [];
    private array $thresholds;
    private array $activeMonitors = [];

    public function __construct(SecurityManager $security, array $config = [])
    {
        $this->security = $security;
        $this->thresholds = array_merge([
            'response_time' => 200,
            'cpu_usage' => 70,
            'memory_usage' => 80,
            'error_rate' => 1,
            'cache_hit_ratio' => 80
        ], $config['thresholds'] ?? []);
    }

    public function startOperation(string $operationType, array $context = []): string
    {
        $monitorId = $this->generateMonitorId();
        
        $this->activeMonitors[$monitorId] = [
            'type' => $operationType,
            'context' => $context,
            'start_time' => microtime(true),
            'metrics' => [],
            'alerts' => []
        ];

        return $monitorId;
    }

    public function trackMetric(string $monitorId, string $metric, $value): void
    {
        if (!isset($this->activeMonitors[$monitorId])) {
            throw new MonitoringException('Invalid monitor ID');
        }

        $this->activeMonitors[$monitorId]['metrics'][$metric] = [
            'value' => $value,
            'timestamp' => microtime(true)
        ];

        $this->checkThreshold($monitorId, $metric, $value);
    }

    public function endOperation(string $monitorId): array
    {
        if (!isset($this->activeMonitors[$monitorId])) {
            throw new MonitoringException('Invalid monitor ID');
        }

        $monitor = $this->activeMonitors[$monitorId];
        $duration = microtime(true) - $monitor['start_time'];

        $result = [
            'type' => $monitor['type'],
            'duration' => $duration,
            'metrics' => $monitor['metrics'],
            'alerts' => $monitor['alerts']
        ];

        $this->recordOperationMetrics($result);
        unset($this->activeMonitors[$monitorId]);

        return $result;
    }

    public function getSystemHealth(): array
    {
        return $this->security->executeCriticalOperation(
            function() {
                return [
                    'status' => $this->calculateSystemStatus(),
                    'metrics' => $this->collectSystemMetrics(),
                    'alerts' => $this->getActiveAlerts(),
                    'resources' => $this->getResourceUsage()
                ];
            },
            ['operation' => 'system_health_check']
        );
    }

    public function getPerformanceMetrics(): array
    {
        return [
            'response_times' => $this->calculateAverageResponseTimes(),
            'error_rates' => $this->calculateErrorRates(),
            'resource_usage' => $this->getResourceUsage(),
            'cache_performance' => $this->getCacheMetrics()
        ];
    }

    protected function generateMonitorId(): string
    {
        return uniqid('monitor_', true);
    }

    protected function checkThreshold(string $monitorId, string $metric, $value): void
    {
        if (!isset($this->thresholds[$metric])) {
            return;
        }

        if ($this->isThresholdExceeded($metric, $value)) {
            $alert = [
                'type' => 'threshold_exceeded',
                'metric' => $metric,
                'value' => $value,
                'threshold' => $this->thresholds[$metric],
                'timestamp' => microtime(true)
            ];

            $this->activeMonitors[$monitorId]['alerts'][] = $alert;
            $this->handleAlert($alert);
        }
    }

    protected function isThresholdExceeded(string $metric, $value): bool
    {
        switch ($metric) {
            case 'response_time':
                return $value > $this->thresholds[$metric];
            case 'cpu_usage':
            case 'memory_usage':
                return $value > $this->thresholds[$metric];
            case 'error_rate':
                return $value > $this->thresholds[$metric];
            case 'cache_hit_ratio':
                return $value < $this->thresholds[$metric];
            default:
                return false;
        }
    }

    protected function handleAlert(array $alert): void
    {
        event(new MonitoringEvent('alert', $alert));

        Log::warning('Monitoring alert', [
            'alert' => $alert,
            'system_state' => $this->getSystemHealth()
        ]);

        if ($this->isEmergencyAlert($alert)) {
            $this->triggerEmergencyProtocol($alert);
        }
    }

    protected function isEmergencyAlert(array $alert): bool
    {
        return $alert['type'] === 'threshold_exceeded' && 
               ($alert['metric'] === 'cpu_usage' && $alert['value'] > 90 ||
                $alert['metric'] === 'memory_usage' && $alert['value'] > 95 ||
                $alert['metric'] === 'error_rate' && $alert['value'] > 10);
    }

    protected function triggerEmergencyProtocol(array $alert): void
    {
        event(new MonitoringEvent('emergency', $alert));
        
        Log::emergency('System emergency alert', [
            'alert' => $alert,
            'system_state' => $this->getSystemHealth()
        ]);
    }

    protected function calculateSystemStatus(): string
    {
        $metrics = $this->collectSystemMetrics();
        $criticalIssues = array_filter($metrics, fn($m) => $m['severity'] === 'critical');
        
        if (!empty($criticalIssues)) {
            return 'critical';
        }

        $warnings = array_filter($metrics, fn($m) => $m['severity'] === 'warning');
        return !empty($warnings) ? 'warning' : 'healthy';
    }

    protected function collectSystemMetrics(): array
    {
        return [
            'performance' => $this->getPerformanceMetrics(),
            'security' => $this->getSecurityMetrics(),
            'resources' => $this->getResourceUsage(),
            'errors' => $this->getErrorMetrics()
        ];
    }

    protected function getResourceUsage(): array
    {
        return [
            'cpu' => sys_getloadavg()[0],
            'memory' => memory_get_usage(true),
            'disk' => disk_free_space('/'),
            'connections' => DB::connection()->select('SHOW STATUS LIKE "Threads_connected"')[0]->Value ?? 0
        ];
    }

    protected function getCacheMetrics(): array
    {
        $stats = Cache::getStore()->connection()->info();
        
        return [
            'hits' => $stats['keyspace_hits'] ?? 0,
            'misses' => $stats['keyspace_misses'] ?? 0,
            'memory_used' => $stats['used_memory'] ?? 0,
            'total_connections' => $stats['total_connections_received'] ?? 0
        ];
    }

    protected function recordOperationMetrics(array $result): void
    {
        $this->metrics[] = $result;
        
        if (count($this->metrics) > 1000) {
            array_shift($this->metrics);
        }
    }
}
