<?php

namespace App\Core\Monitoring;

use Illuminate\Support\Facades\{Cache, Log};
use App\Core\Interfaces\MonitoringInterface;

final class CriticalMonitor implements MonitoringInterface
{
    private MetricsCollector $metrics;
    private PerformanceAnalyzer $analyzer;
    private AlertSystem $alerts;

    private const METRICS_PREFIX = 'critical_operation_';
    private const ALERT_THRESHOLD = 90; // percentage

    public function __construct(
        MetricsCollector $metrics,
        PerformanceAnalyzer $analyzer,
        AlertSystem $alerts
    ) {
        $this->metrics = $metrics;
        $this->analyzer = $analyzer;
        $this->alerts = $alerts;
    }

    public function initializeOperation(): string
    {
        $operationId = uniqid('op_', true);
        
        $this->metrics->initializeMetrics($operationId, [
            'start_time' => microtime(true),
            'memory_start' => memory_get_usage(true),
            'cpu_start' => $this->analyzer->getCurrentCPUUsage()
        ]);

        return $operationId;
    }

    public function finalizeOperation(string $operationId): void
    {
        $metrics = $this->metrics->getMetrics($operationId);
        $endTime = microtime(true);
        
        $this->metrics->updateMetrics($operationId, [
            'duration' => $endTime - $metrics['start_time'],
            'memory_peak' => memory_get_peak_usage(true),
            'cpu_peak' => $this->analyzer->getPeakCPUUsage(),
            'completed' => true
        ]);

        $this->analyzeOperation($operationId);
    }

    public function trackExecution(callable $operation)
    {
        $startTime = microtime(true);
        $result = $operation();
        $duration = microtime(true) - $startTime;

        if ($duration > 0.2) { // 200ms threshold
            $this->alerts->triggerPerformanceAlert([
                'duration' => $duration,
                'threshold' => 0.2
            ]);
        }

        return $result;
    }

    public function checkThresholds(array $thresholds): bool
    {
        $currentMetrics = [
            'memory' => memory_get_usage(true) / 1024 / 1024,
            'cpu' => $this->analyzer->getCurrentCPUUsage(),
            'response_time' => $this->analyzer->getAverageResponseTime()
        ];

        foreach ($thresholds as $metric => $threshold) {
            if (isset($currentMetrics[$metric]) && $currentMetrics[$metric] > $threshold) {
                $this->alerts->triggerThresholdAlert($metric, $currentMetrics[$metric], $threshold);
                return false;
            }
        }

        return true;
    }

    public function getOperationMetrics(string $operationId): array
    {
        return $this->metrics->getMetrics($operationId);
    }

    public function recordFailure(string $operationId): void
    {
        $this->metrics->markFailure($operationId);
        $this->alerts->triggerFailureAlert($operationId);
    }

    private function analyzeOperation(string $operationId): void
    {
        $metrics = $this->metrics->getMetrics($operationId);
        $analysis = $this->analyzer->analyzeOperation($metrics);

        if ($analysis['risk_level'] > self::ALERT_THRESHOLD) {
            $this->alerts->triggerRiskAlert($analysis);
        }

        Log::info('Operation analysis', [
            'operation_id' => $operationId,
            'metrics' => $metrics,
            'analysis' => $analysis
        ]);
    }
}
