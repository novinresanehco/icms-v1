<?php

namespace App\Core\Monitoring;

class SystemMonitor implements MonitorInterface 
{
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private Logger $logger;

    public function monitorSystem(): void 
    {
        try {
            $metrics = $this->collectSystemMetrics();
            $this->analyzeMetrics($metrics);
            $this->storeMetrics($metrics);
            $this->checkThresholds($metrics);
        } catch (\Exception $e) {
            $this->handleMonitoringFailure($e);
        }
    }

    private function collectSystemMetrics(): array 
    {
        return [
            'cpu_usage' => $this->metrics->getCpuUsage(),
            'memory_usage' => $this->metrics->getMemoryUsage(),
            'disk_usage' => $this->metrics->getDiskUsage(),
            'response_time' => $this->metrics->getAverageResponseTime(),
            'error_rate' => $this->metrics->getErrorRate(),
            'active_users' => $this->metrics->getActiveUsers(),
            'timestamp' => time()
        ];
    }

    private function analyzeMetrics(array $metrics): void 
    {
        $analysis = $this->analyzer->analyze($metrics);
        
        if ($analysis->hasWarnings()) {
            foreach ($analysis->getWarnings() as $warning) {
                $this->alerts->sendWarning($warning);
            }
        }

        if ($analysis->hasCriticalIssues()) {
            foreach ($analysis->getCriticalIssues() as $issue) {
                $this->alerts->sendCriticalAlert($issue);
            }
        }
    }

    private function checkThresholds(array $metrics): void 
    {
        $thresholds = [
            'cpu_usage' => 70,
            'memory_usage' => 80,
            'disk_usage' => 85,
            'response_time' => 200,
            'error_rate' => 1
        ];

        foreach ($thresholds as $metric => $threshold) {
            if ($metrics[$metric] > $threshold) {
                $this->alerts->sendThresholdAlert($metric, $metrics[$metric], $threshold);
            }
        }
    }

    private function handleMonitoringFailure(\Exception $e): void 
    {
        $this->logger->critical('Monitoring system failure', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->alerts->sendCriticalAlert(
            'Monitoring system failure detected'
        );
    }
}