<?php

namespace App\Core\Infrastructure\Monitoring;

use Illuminate\Support\Facades\{Cache, DB, Log};
use App\Core\Security\SecurityManager;
use App\Core\Infrastructure\Metrics\{
    PerformanceMetrics,
    SecurityMetrics,
    ResourceMetrics
};

class MonitoringMetricsSystem
{
    private SecurityManager $security;
    private PerformanceMetrics $performance;
    private SecurityMetrics $securityMetrics;
    private ResourceMetrics $resources;
    private AuditLogger $auditLogger;
    private array $criticalThresholds;

    public function __construct(
        SecurityManager $security,
        PerformanceMetrics $performance,
        SecurityMetrics $securityMetrics,
        ResourceMetrics $resources,
        AuditLogger $auditLogger,
        array $criticalThresholds
    ) {
        $this->security = $security;
        $this->performance = $performance;
        $this->securityMetrics = $securityMetrics;
        $this->resources = $resources;
        $this->auditLogger = $auditLogger;
        $this->criticalThresholds = $criticalThresholds;
    }

    public function collectCriticalMetrics(): MetricsReport
    {
        return $this->security->executeCriticalOperation(
            new CollectMetricsOperation([
                'performance' => $this->performance,
                'security' => $this->securityMetrics,
                'resources' => $this->resources,
                'thresholds' => $this->criticalThresholds,
                'logger' => $this->auditLogger
            ])
        );
    }

    protected function validateMetrics(array $metrics): void
    {
        foreach ($metrics as $key => $value) {
            if (!$this->isMetricValid($key, $value)) {
                $this->handleMetricViolation($key, $value);
            }
        }
    }

    private function isMetricValid(string $key, $value): bool
    {
        $threshold = $this->criticalThresholds[$key] ?? null;
        if (!$threshold) {
            throw new ConfigurationException("No threshold defined for metric: {$key}");
        }

        return $value <= $threshold;
    }

    private function handleMetricViolation(string $metric, $value): void
    {
        $this->auditLogger->logMetricViolation($metric, $value);
        
        if ($this->isEmergencyAction($metric, $value)) {
            $this->executeEmergencyProtocol($metric, $value);
        }

        throw new MetricViolationException(
            "Critical metric violation: {$metric} = {$value}"
        );
    }
}

class CollectMetricsOperation implements CriticalOperation
{
    private array $services;

    public function execute(): MetricsReport
    {
        // Collect performance metrics
        $performance = $this->collectPerformanceMetrics();
        
        // Collect security metrics
        $security = $this->collectSecurityMetrics();
        
        // Collect resource metrics
        $resources = $this->collectResourceMetrics();

        // Validate all metrics
        $this->validateMetrics([
            'performance' => $performance,
            'security' => $security,
            'resources' => $resources
        ]);

        // Generate comprehensive report
        $report = new MetricsReport([
            'performance' => $performance,
            'security' => $security,
            'resources' => $resources,
            'timestamp' => now()
        ]);

        // Log metrics collection
        $this->services['logger']->logMetricsCollection($report);

        return $report;
    }

    private function collectPerformanceMetrics(): array
    {
        return [
            'response_time' => $this->services['performance']->measureResponseTime(),
            'throughput' => $this->services['performance']->measureThroughput(),
            'error_rate' => $this->services['performance']->calculateErrorRate(),
            'queue_length' => $this->services['performance']->getQueueLength(),
            'cache_hit_ratio' => $this->services['performance']->getCacheHitRatio()
        ];
    }

    private function collectSecurityMetrics(): array
    {
        return [
            'failed_logins' => $this->services['security']->getFailedLogins(),
            'suspicious_activities' => $this->services['security']->getSuspiciousActivities(),
            'encryption_status' => $this->services['security']->checkEncryptionStatus(),
            'access_violations' => $this->services['security']->getAccessViolations()
        ];
    }

    private function collectResourceMetrics(): array
    {
        return [
            'cpu_usage' => $this->services['resources']->getCpuUsage(),
            'memory_usage' => $this->services['resources']->getMemoryUsage(),
            'disk_usage' => $this->services['resources']->getDiskUsage(),
            'network_load' => $this->services['resources']->getNetworkLoad(),
            'connection_pool' => $this->services['resources']->getConnectionPoolStatus()
        ];
    }

    private function validateMetrics(array $metrics): void
    {
        foreach ($metrics as $category => $categoryMetrics) {
            foreach ($categoryMetrics as $metric => $value) {
                $threshold = $this->services['thresholds']["{$category}.{$metric}"] ?? null;
                if ($threshold && $value > $threshold) {
                    throw new MetricViolationException(
                        "Threshold exceeded for {$category}.{$metric}: {$value}"
                    );
                }
            }
        }
    }
}
