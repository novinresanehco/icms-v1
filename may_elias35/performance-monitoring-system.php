<?php

namespace App\Core\Performance;

use App\Core\Monitoring\SystemMonitor;
use App\Core\Metrics\MetricsCollector;
use App\Core\Alert\AlertManager;

class PerformanceManager implements PerformanceInterface
{
    private SystemMonitor $monitor;
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private array $thresholds;
    private array $activeMonitoring = [];

    public function __construct(
        SystemMonitor $monitor,
        MetricsCollector $metrics,
        AlertManager $alerts,
        array $thresholds
    ) {
        $this->monitor = $monitor;
        $this->metrics = $metrics;
        $this->alerts = $alerts;
        $this->thresholds = $thresholds;
    }

    public function startMonitoring(string $operation): string
    {
        $monitoringId = $this->generateMonitoringId();
        
        $this->activeMonitoring[$monitoringId] = [
            'operation' => $operation,
            'start_time' => hrtime(true),
            'metrics' => [],
            'state' => 'active'
        ];

        $this->monitor->recordMetric(
            $monitoringId,
            'operation_start',
            microtime(true)
        );

        return $monitoringId;
    }

    public function recordMetric(string $monitoringId, string $metric, $value): void
    {
        if (!isset($this->activeMonitoring[$monitoringId])) {
            throw new PerformanceException('Invalid monitoring ID');
        }

        $this->activeMonitoring[$monitoringId]['metrics'][$metric] = [
            'value' => $value,
            'timestamp' => hrtime(true)
        ];

        $this->checkThresholds($metric, $value);
    }

    public function endMonitoring(string $monitoringId): array
    {
        if (!isset($this->activeMonitoring[$monitoringId])) {
            throw new PerformanceException('Invalid monitoring ID');
        }

        $monitoring = $this->activeMonitoring[$monitoringId];
        $endTime = hrtime(true);
        $duration = ($endTime - $monitoring['start_time']) / 1e9;

        $metrics = [
            'duration' => $duration,
            'metrics' => $monitoring['metrics']
        ];

        $this->metrics->record($monitoring['operation'], $metrics);
        
        unset($this->activeMonitoring[$monitoringId]);

        return $metrics;
    }

    public function analyzePerformance(string $operation, array $metrics): PerformanceReport
    {
        $report = new PerformanceReport();

        foreach ($metrics as $metric => $value) {
            $threshold = $this->thresholds[$metric] ?? null;
            
            if ($threshold) {
                $report->addMetric($metric, $value, $this->evaluateMetric($value, $threshold));
            }
        }

        if ($report->hasWarnings()) {
            $this->alerts->triggerPerformanceWarning($operation, $report);
        }

        if ($report->hasCriticalIssues()) {
            $this->alerts->triggerPerformanceCritical($operation, $report);
        }

        return $report;
    }

    private function checkThresholds(string $metric, $value): void
    {
        if (isset($this->thresholds[$metric])) {
            $threshold = $this->thresholds[$metric];

            if ($value > $threshold['critical']) {
                $this->alerts->triggerCriticalPerformance($metric, $value);
            } elseif ($value > $threshold['warning']) {
                $this->alerts->triggerWarningPerformance($metric, $value);
            }
        }
    }

    private function evaluateMetric($value, array $threshold): string
    {
        if ($value > $threshold['critical']) {
            return 'critical';
        }
        if ($value > $threshold['warning']) {
            return 'warning';
        }
        return 'normal';
    }

    private function generateMonitoringId(): string
    {
        return uniqid('perf_', true);
    }
}
