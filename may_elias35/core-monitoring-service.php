<?php

namespace App\Core\Monitoring;

use App\Core\Security\SecurityManager;
use App\Core\Infrastructure\HealthCheck;
use App\Core\Metrics\MetricsCollector;
use App\Core\Audit\AuditLogger;

class CoreMonitoringService implements MonitoringInterface
{
    private SecurityManager $security;
    private HealthCheck $health;
    private MetricsCollector $metrics;
    private AuditLogger $audit;

    private const ALERT_THRESHOLD = 90; // percentage
    private const CHECK_INTERVAL = 5; // seconds
    private const MAX_LOAD = 80; // percentage

    public function __construct(
        SecurityManager $security,
        HealthCheck $health,
        MetricsCollector $metrics,
        AuditLogger $audit
    ) {
        $this->security = $security;
        $this->health = $health;
        $this->metrics = $metrics;
        $this->audit = $audit;
    }

    public function monitor(): MonitoringResult
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

            // Generate result
            $result = new MonitoringResult(
                $healthStatus,
                $securityStatus,
                $performanceMetrics,
                $resourceStatus
            );

            // Handle alerts if needed
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

    private function collectPerformanceMetrics(): array
    {
        return [
            'response_time' => $this->metrics->getAverageResponseTime(),
            'throughput' => $this->metrics->getCurrentThroughput(),
            'error_rate' => $this->metrics->getErrorRate(),
            'queue_size' => $this->metrics->getQueueSize(),
            'cache_hit_ratio' => $this->metrics->getCacheHitRatio()
        ];
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

    private function handleAlerts(MonitoringResult $result): void
    {
        foreach ($result->getAlerts() as $alert) {
            $this->processAlert($alert);
        }
    }

    private function processAlert(Alert $alert): void
    {
        // Log alert
        $this->audit->logAlert([
            'type' => $alert->getType(),
            'severity' => $alert->getSeverity(),
            'message' => $alert->getMessage(),
            'timestamp' => now()
        ]);

        // Execute alert protocols
        if ($alert->isCritical()) {
            $this->executeCriticalAlertProtocol($alert);
        }
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

    private function handleResourceOverload(ResourceStatus $status): void
    {
        $this->audit->logCritical('resource_overload', [
            'status' => $status->toArray(),
            'timestamp' => now()
        ]);

        $this->executeResourceProtocols($status);
    }

    private function executeHealthProtocols(HealthStatus $status): void
    {
        foreach ($status->getIssues() as $issue) {
            $this->executeRecoveryProtocol($issue);
        }
    }

    private function executeResourceProtocols(ResourceStatus $status): void
    {
        if ($status->getCpuUsage() > self::MAX_LOAD) {
            $this->reduceCpuLoad();
        }

        if ($status->getMemoryUsage() > self::MAX_LOAD) {
            $this->freeMemory();
        }
    }

    private function executeCriticalAlertProtocol(Alert $alert): void
    {
        switch ($alert->getType()) {
            case 'security':
                $this->security->handleSecurityAlert($alert);
                break;
            case 'performance':
                $this->executePerformanceProtocol($alert);
                break;
            case 'resource':
                $this->executeResourceProtocol($alert);
                break;
        }
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

    private function getActiveConnections(): int
    {
        return $this->metrics->getActiveConnections();
    }

    private function reduceCpuLoad(): void
    {
        $this->metrics->throttleRequests();
        $this->cache->optimize();
    }

    private function freeMemory(): void
    {
        $this->cache->clear();
        gc_collect_cycles();
    }
}
