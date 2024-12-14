<?php
namespace App\Core\Infrastructure;

final class SystemMonitor implements MonitorInterface
{
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private Logger $logger;
    private PerformanceAnalyzer $analyzer;

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
            'request_rate' => $this->metrics->getRequestRate(),
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
                $this->executeOptimization($metric);
            }
        }
    }

    private function executeOptimization(string $metric): void
    {
        switch ($metric) {
            case 'cpu_usage':
                $this->optimizeProcesses();
                break;
            case 'memory_usage':
                $this->freeMemory();
                break;
            case 'response_time':
                $this->optimizePerformance();
                break;
            default:
                $this->executeGeneralOptimization();
        }
    }
}