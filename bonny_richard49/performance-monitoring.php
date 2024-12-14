<?php

namespace App\Core\Infrastructure\Monitoring;

class PerformanceMonitor implements MonitorInterface
{
    private MetricsCollector $metrics;
    private ThresholdManager $thresholds;
    private AlertSystem $alerts;
    private LogManager $logs;

    public function monitor(string $metric, $value): void
    {
        // Record metric
        $this->metrics->record($metric, $value);
        
        // Check against thresholds
        $this->checkThresholds($metric, $value);
        
        // Log metric
        $this->logs->logMetric($metric, $value);
    }

    private function checkThresholds(string $metric, $value): void
    {
        $threshold = $this->thresholds->get($metric);
        
        if ($this->isThresholdExceeded($value, $threshold)) {
            $this->handleThresholdViolation($metric, $value, $threshold);
        }
    }

    private function handleThresholdViolation(string $metric, $value, Threshold $threshold): void
    {
        $this->alerts->sendAlert(new ThresholdAlert(
            $metric,
            $value,
            $threshold
        ));
    }
}

class ResourceMonitor implements ResourceMonitorInterface
{
    private SystemStats $stats;
    private AlertManager $alerts;
    private Config $config;

    public function monitorResources(): ResourceMetrics
    {
        $metrics = new ResourceMetrics([
            'cpu' => $this->stats->getCpuUsage(),
            'memory' => $this->stats->getMemoryUsage(),
            'disk' => $this->stats->getDiskUsage(),
            'network' => $this->stats->getNetworkUsage()
        ]);

        $this->checkResourceLimits($metrics);
        
        return $metrics;
    }

    private function checkResourceLimits(ResourceMetrics $metrics): void
    {
        foreach ($metrics->toArray() as $resource => $usage) {
            $limit = $this->config->getResourceLimit($resource);
            
            if ($usage > $limit) {
                $this->handleResourceLimit($resource, $usage, $limit);
            }
        }
    }

    private function handleResourceLimit(string $resource, float $usage, float $limit): void
    {
        $this->alerts->sendResourceAlert(new ResourceAlert(
            $resource,
            $usage,
            $limit
        ));
    }
}

class SecurityMonitor implements SecurityMonitorInterface
{
    private IdsManager $ids;
    private FirewallManager $firewall;
    private AuditLogger $logger;
    private AlertSystem $alerts;

    public function monitorSecurity(): void
    {
        // Check IDS alerts
        $this->checkIdsAlerts();
        
        // Monitor firewall logs
        $this->monitorFirewall();
        
        // Review audit logs
        $this->reviewAuditLogs();
    }

    private function checkIdsAlerts(): void
    {
        $alerts = $this->ids->getAlerts();
        
        foreach ($alerts as $alert) {
            $this->handleSecurityAlert($alert);
        }
    }

    private function monitorFirewall(): void
    {
        $logs = $this->firewall->getLogs();
        
        foreach ($logs as $log) {
            if ($this->isSecurityThreat($log)) {
                $this->handleSecurityThreat($log);
            }
        }
    }

    private function handleSecurityAlert(SecurityAlert $alert): void
    {
        $this->logger->logSecurityAlert($alert);
        $this->alerts->sendSecurityAlert($alert);
    }

    private function handleSecurityThreat(SecurityThreat $threat): void
    {
        $this->firewall->blockThreat($threat);
        $this->alerts->sendThreatAlert($threat);
        $this->logger->logSecurityThreat($threat);
    }
}