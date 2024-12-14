<?php
namespace App\Infrastructure\Monitoring;

class MonitoringSystem {
    private MetricsAggregator $metrics;
    private AlertDispatcher $alerts;
    private PerformanceAnalyzer $analyzer;
    private LogManager $logger;
    
    public function recordMetrics(string $category, array $data): void {
        try {
            $normalized = $this->normalizeMetrics($data);
            $this->metrics->record($category, $normalized);
            
            if ($this->analyzer->shouldAlert($category, $normalized)) {
                $this->dispatchAlert($category, $normalized);
            }
            
            $this->logger->logMetrics($category, $normalized);
            
        } catch (\Exception $e) {
            $this->handleMonitoringFailure($e);
        }
    }

    public function getSystemStatus(): array {
        return [
            'performance' => $this->analyzer->getCurrentPerformance(),
            'resources' => $this->analyzer->getResourceUtilization(),
            'security' => $this->analyzer->getSecurityStatus(),
            'errors' => $this->analyzer->getErrorMetrics()
        ];
    }

    private function normalizeMetrics(array $data): array {
        return array_merge($data, [
            'timestamp' => microtime(true),
            'environment' => config('app.env'),
            'server_id' => gethostname()
        ]);
    }

    private function dispatchAlert(string $category, array $metrics): void {
        $this->alerts->dispatch(
            $this->analyzer->determineAlertLevel($category, $metrics),
            $metrics
        );
    }
}

interface MetricsAggregator {
    public function record(string $category, array $data): void;
    public function aggregate(string $category, string $interval): array;
}

interface AlertDispatcher {
    public function dispatch(string $level, array $metrics): void;
}

interface PerformanceAnalyzer {
    public function shouldAlert(string $category, array $metrics): bool;
    public function determineAlertLevel(string $category, array $metrics): string;
    public function getCurrentPerformance(): array;
    public function getResourceUtilization(): array;
    public function getSecurityStatus(): array;
    public function getErrorMetrics(): array;
}
