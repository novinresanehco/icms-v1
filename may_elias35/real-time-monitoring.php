<?php

namespace App\Core\Monitoring;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Infrastructure\HealthCheck;
use App\Core\Metrics\MetricsCollector;
use App\Core\Audit\AuditLogger;

class RealTimeMonitoring implements MonitoringInterface
{
    private SecurityManagerInterface $security;
    private HealthCheck $health;
    private MetricsCollector $metrics;
    private AuditLogger $audit;

    private const ALERT_THRESHOLD = 90; // percentage
    private const CHECK_INTERVAL = 5; // seconds
    private const MAX_LOAD = 80; // percentage

    public function __construct(
        SecurityManagerInterface $security,
        HealthCheck $health,
        MetricsCollector $metrics,
        AuditLogger $audit
    ) {
        $this->security = $security;
        $this->health = $health;
        $this->metrics = $metrics;
        $this->audit = $audit;
    }

    public function monitorSystem(): MonitoringResult
    {
        $monitoringId = $this->startMonitoring();

        try {
            // System health check
            $healthStatus = $this->checkSystemHealth();

            // Security status check
            $securityStatus = $this->checkSecurityStatus();

            // Performance metrics
            $performanceMetrics = $this->collectPerformanceMetrics();

            // Resource utilization
            $resourceStatus = $this->checkResourceUtilization();

            // Generate monitoring result
            $result = $this->generateResult(
                $healthStatus,
                $securityStatus,
                $performanceMetrics,
                $resourceStatus
            );

            // Handle any alerts
            $this->handleAlerts($result);

            return $result;

        } catch (\Exception $e) {
            $this->handleMonitoringFailure($e);
            throw $e;
        } finally {
            $this->endMonitoring($monitoringId);
        }
    }

    private function startMonitoring(): string
    {
        $monitoringId = uniqid('monitor_', true);
        
        $this->audit->logMonitoringStart([
            'id' => $monitoringId,
            'timestamp' => now()
        ]);

        return $monitoringId;
    }

    private function checkSystemHealth(): HealthStatus
    {
        $status = $this->health->checkSystem();

        if (!$status->isHealthy()) {
            $this->handleUnhealthySystem($status);
        }

        return $status;
    }

    private function checkSecurityStatus(): SecurityStatus
    {
        $status = $this->security->getStatus();

        if (!$status->isSecure()) {
            $this->handleSecurityIssue($status);
        }

        return $status;
    }

    private function collectPerformanceMetrics(): PerformanceMetrics
    {
        $metrics = $this->metrics->collect([
            'response_time',
            'throughput',
            'error_rate',
            'queue_size'
        ]);

        if ($metrics->hasAnomalies()) {
            $this->handlePerformanceAnomalies($metrics);
        }

        return $metrics;
    }

    private function checkResourceUtilization(): ResourceStatus
    {
        $status = new ResourceStatus([
            'cpu' => sys_getloadavg()[0],
            'memory' => memory_get_usage(true),
            'disk' => disk_free_space('/'),
            'connections' => $this->getActiveConnections()
        ]);

        if ($status->isOverloaded()) {
            $this->handleResourceOverload($status);
        }

        return $status;
    }

    private function generateResult(
        HealthStatus $health,
        SecurityStatus $security,
        PerformanceMetrics $performance,
        ResourceStatus $resources
    ): MonitoringResult {
        return new MonitoringResult(
            health: $health,
            security: $security,
            performance: $performance,
            resources: $resources,
            timestamp: now()
        );
    }

    private function handleAlerts(MonitoringResult $result): void
    {
        foreach ($result->getAlerts() as $alert) {
            $this->processAlert($alert);
        }
    }

    private function processAlert(Alert $alert): void
    {
        // Log alert
        $this->audit->logAlert($alert);

        // Execute alert-specific protocols
        if ($alert->isCritical()) {
            $this->executeCriticalAlertProtocol($alert);
        }

        // Notify relevant parties
        $this->notifyAlert($alert);
    }

    private function handleUnhealthySystem(HealthStatus $status): void
    {
        $this->audit->logCritical('system_unhealthy', [
            'status' => $status->toArray(),
            'timestamp' => now()
        ]);

        $this->executeHealthProtocols($status);
    }

    private function handleSecurityIssue(SecurityStatus $status): void
    {
        $this->audit->logCritical('security_issue', [
            'status' => $status->toArray(),
            'timestamp' => now()
        ]);

        $this->security->handleSecurityIssue($status);
    }

    private function handlePerformanceAnomalies(PerformanceMetrics $metrics): void
    {
        $this->audit->logWarning('performance_anomaly', [
            'metrics' => $metrics->toArray(),
            'timestamp' => now()
        ]);

        $this->executePerformanceProtocols($metrics);
    }

    private function handleResourceOverload(ResourceStatus $status): void
    {
        $this->audit->logCritical('resource_overload', [
            'status' => $status->toArray(),
            'timestamp' => now()
        ]);

        $this->executeResourceProtocols($status);
    }

    private function handleMonitoringFailure(\Exception $e): void
    {
        $this->audit->logCritical('monitoring_failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => now()
        ]);

        if ($e instanceof SecurityException) {
            $this->security->handleSecurityFailure($e);
        }
    }

    private function endMonitoring(string $monitoringId): void
    {
        $this->audit->logMonitoringEnd([
            'id' => $monitoringId,
            'timestamp' => now()
        ]);
    }
}
