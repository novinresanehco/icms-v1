<?php

namespace App\Http\Controllers\Admin;

use App\Core\Monitoring\{
    PerformanceMonitor,
    SecurityMonitor,
    ResourceMonitor
};
use App\Core\Analytics\MetricsCollector;
use App\Core\Cache\CacheManager;
use App\Core\Security\AuditLogger;

class AdminDashboardController extends Controller
{
    private PerformanceMonitor $performance;
    private SecurityMonitor $security;
    private ResourceMonitor $resources;
    private MetricsCollector $metrics;
    private CacheManager $cache;
    private AuditLogger $audit;

    public function index(): View
    {
        // Get real-time metrics
        $systemMetrics = $this->getRealTimeMetrics();
        
        // Security status
        $securityStatus = $this->getSecurityStatus();
        
        // Resource utilization
        $resourceStatus = $this->getResourceStatus();

        return view('admin.dashboard', compact(
            'systemMetrics',
            'securityStatus', 
            'resourceStatus'
        ));
    }

    protected function getRealTimeMetrics(): array
    {
        return $this->cache->remember('dashboard.metrics', 60, function() {
            return [
                'performance' => $this->performance->getCurrentMetrics(),
                'errors' => $this->performance->getErrorRates(),
                'traffic' => $this->metrics->getTrafficStats(),
                'response_times' => $this->performance->getResponseTimes()
            ];
        });
    }

    protected function getSecurityStatus(): array 
    {
        return [
            'threats' => $this->security->getActiveThreats(),
            'alerts' => $this->security->getPendingAlerts(),
            'audit_log' => $this->audit->getRecentEntries(),
            'access_log' => $this->security->getRecentAccess()
        ];
    }

    protected function getResourceStatus(): array
    {
        return [
            'cpu' => $this->resources->getCpuUtilization(),
            'memory' => $this->resources->getMemoryUsage(),
            'disk' => $this->resources->getDiskUsage(),
            'cache' => $this->cache->getStatus(),
            'queue' => $this->resources->getQueueStatus()
        ];
    }

    public function getMetrics(Request $request): JsonResponse
    {
        $type = $request->get('type', 'all');
        $duration = $request->get('duration', '1h');

        $metrics = match($type) {
            'performance' => $this->performance->getMetrics($duration),
            'security' => $this->security->getMetrics($duration),
            'resources' => $this->resources->getMetrics($duration),
            default => $this->metrics->getAllMetrics($duration)
        };

        return response()->json([
            'metrics' => $metrics,
            'timestamp' => now(),
            'interval' => $duration
        ]);
    }

    public function getAlerts(): JsonResponse
    {
        return response()->json([
            'security' => $this->security->getActiveAlerts(),
            'performance' => $this->performance->getActiveAlerts(),
            'resources' => $this->resources->getActiveAlerts()
        ]);
    }

    protected function validateThresholds(array $metrics): void
    {
        foreach ($metrics as $metric => $value) {
            if ($value > config("monitoring.thresholds.{$metric}")) {
                $this->triggerAlert($metric, $value);
            }
        }
    }

    protected function triggerAlert(string $metric, float $value): void
    {
        $this->notifications->sendAlert(
            "High {$metric} detected: {$value}",
            AlertLevel::WARNING
        );

        $this->audit->logAlert($metric, [
            'value' => $value,
            'threshold' => config("monitoring.thresholds.{$metric}"),
            'timestamp' => now()
        ]);
    }
}
