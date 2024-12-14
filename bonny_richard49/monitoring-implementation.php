<?php

namespace App\Monitoring;

class SystemMonitoringService implements MonitoringInterface
{
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private HealthChecker $health;
    private AuditLogger $logger;

    public function monitor(): void
    {
        try {
            // Collect metrics
            $metrics = $this->collectSystemMetrics();
            
            // Check system health
            $health = $this->checkSystemHealth();
            
            // Analyze metrics
            $this->analyzeMetrics($metrics);
            
            // Log status
            $this->logSystemStatus($metrics, $health);
            
        } catch (\Exception $e) {
            $this->handleMonitoringFailure($e);
        }
    }

    private function collectSystemMetrics(): array
    {
        return [
            'cpu' => $this->metrics->getCpuUsage(),
            'memory' => $this->metrics->getMemoryUsage(),
            'disk' => $this->metrics->getDiskUsage(),
            'network' => $this->metrics->getNetworkUsage(),
            'users' => $this->metrics->getActiveUsers(),
            'requests' => $this->metrics->getRequestRate(),
            'errors' => $this->metrics->getErrorRate()
        ];
    }

    private function checkSystemHealth(): HealthStatus
    {
        return $this->health->check([
            'database' => fn() => $this->checkDatabaseHealth(),
            'cache' => fn() => $this->checkCacheHealth(),
            'storage' => fn() => $this->checkStorageHealth(),
            'services' => fn() => $this->checkServicesHealth()
        ]);
    }

    private function analyzeMetrics(array $metrics): void
    {
        foreach ($metrics as $metric => $value) {
            if ($this->isThresholdExceeded($metric, $value)) {
                $this->handleThresholdViolation($metric, $value);
            }
        }
    }

    private function handleThresholdViolation(string $metric, float $value): void
    {
        $this->alerts->sendThresholdAlert($metric, $value);
        $this->logger->logThresholdViolation($metric, $value);
    }

    private function logSystemStatus(array $metrics, HealthStatus $health): void
    {
        $this->logger->logSystemStatus([
            'metrics' => $metrics,
            'health' => $health->toArray(),
            'timestamp' => now()
        ]);
    }
}

class PerformanceMonitor implements PerformanceInterface
{
    private MetricsCollector $metrics;
    private ThresholdManager $thresholds;
    private OptimizationService $optimizer;
    private AuditLogger $logger;

    public function monitorPerformance(): void
    {
        try {
            // Collect performance metrics
            $metrics = $this->collectPerformanceMetrics();
            
            // Analyze performance
            $analysis = $this->analyzePerformance($metrics);
            
            // Optimize if needed
            if ($analysis->requiresOptimization()) {
                $this->optimizePerformance($analysis);
            }
            
            // Log performance data
            $this->logPerformanceData($metrics, $analysis);
            
        } catch (\Exception $e) {
            $this->handleMonitoringFailure($e);
        }
    }

    private function collectPerformanceMetrics(): array
    {
        return [
            'response_time' => $this->metrics->getAverageResponseTime(),
            'throughput' => $this->metrics->getRequestThroughput(),
            'error_rate' => $this->metrics->getErrorRate(),
            'cpu_usage' => $this->metrics->getCpuUsage(),
            'memory_usage' => $this->metrics->getMemoryUsage()
        ];
    }

    private function analyzePerformance(array $metrics): PerformanceAnalysis
    {
        return new PerformanceAnalysis([
            'metrics' => $metrics,
            'thresholds' => $this->thresholds->getAll(),
            'trends' => $this->metrics->getTrends()
        ]);
    }

    private function optimizePerformance(PerformanceAnalysis $analysis): void
    {
        $this->optimizer->optimize($analysis->getOptimizationTargets());
        $this->logger->logOptimization($analysis);
    }
}