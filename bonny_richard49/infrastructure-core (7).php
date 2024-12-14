<?php
namespace App\Core\Infrastructure;

class SystemMonitor implements MonitorInterface
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
            'Monitoring system failure detected',
            $e->getMessage()
        );
    }
}

class PerformanceOptimizer implements OptimizerInterface
{
    private CacheManager $cache;
    private QueryOptimizer $queryOptimizer;
    private ResourceManager $resources;

    public function optimize(): void
    {
        $this->optimizeQueries();
        $this->optimizeCache();
        $this->optimizeResources();
    }

    private function optimizeQueries(): void
    {
        $this->queryOptimizer->analyzeQueryPatterns();
        $this->queryOptimizer->optimizeFrequentQueries();
        $this->queryOptimizer->updateQueryCache();
    }

    private function optimizeCache(): void
    {
        $this->cache->pruneStaleEntries();
        $this->cache->warmFrequentlyAccessed();
        $this->cache->optimizeMemoryUsage();
    }

    private function optimizeResources(): void
    {
        $this->resources->balanceLoad();
        $this->resources->freeUnusedResources();
        $this->resources->optimizeConnections();
    }
}