<?php

namespace App\Core\Monitoring;

class SystemMonitor implements MonitorInterface
{
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private PerformanceAnalyzer $analyzer;
    private Logger $logger;

    public function monitorSystem(): void
    {
        $this->checkSystemHealth();
        $this->analyzePerformance();
        $this->validateSecurity();
        $this->monitorResources();
    }

    private function checkSystemHealth(): void
    {
        $health = [
            'memory' => $this->getMemoryStatus(),
            'cpu' => $this->getCpuStatus(),
            'disk' => $this->getDiskStatus(),
            'services' => $this->getServiceStatus()
        ];

        foreach ($health as $component => $status) {
            if (!$status['healthy']) {
                $this->handleHealthIssue($component, $status);
            }
        }
    }

    private function analyzePerformance(): void
    {
        $metrics = $this->analyzer->collectMetrics();
        
        if ($this->analyzer->hasPerformanceIssues($metrics)) {
            $this->handlePerformanceIssue($metrics);
        }
    }

    private function handleHealthIssue(string $component, array $status): void
    {
        $this->logger->critical("Health check failed for $component", $status);
        $this->alerts->critical([
            'component' => $component,
            'status' => $status,
            'time' => now()
        ]);
    }
}

class MetricsCollector implements MetricsInterface
{
    private MetricsStore $store;
    private ThresholdManager $thresholds;

    public function collect(): array
    {
        return [
            'system' => $this->collectSystemMetrics(),
            'application' => $this->collectAppMetrics(),
            'security' => $this->collectSecurityMetrics()
        ];
    }

    private function collectSystemMetrics(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'cpu_load' => sys_getloadavg(),
            'disk_usage' => disk_free_space('/'),
            'network_stats' => $this->getNetworkStats()
        ];
    }

    private function collectAppMetrics(): array
    {
        return [
            'response_times' => $this->getResponseTimes(),
            'error_rates' => $this->getErrorRates(),
            'throughput' => $this->getThroughput()
        ];
    }
}
