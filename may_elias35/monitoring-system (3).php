<?php

namespace App\Core\Monitoring;

class CriticalMonitor
{
    private MetricsCollector $metrics;
    private AlertSystem $alerts;
    private AuditLogger $logger;

    public function initializeOperation(): string
    {
        $operationId = uniqid('op_', true);
        
        $this->metrics->initializeMetrics($operationId, [
            'start_time' => microtime(true),
            'memory_start' => memory_get_usage(true),
            'cpu_start' => $this->getCurrentCPUUsage()
        ]);

        return $operationId;
    }

    public function finalizeOperation(string $operationId): void
    {
        $endTime = microtime(true);
        $metrics = $this->metrics->getMetrics($operationId);
        
        $this->metrics->updateMetrics($operationId, [
            'duration' => $endTime - $metrics['start_time'],
            'memory_peak' => memory_get_peak_usage(true),
            'cpu_peak' => $this->getPeakCPUUsage(),
            'completed' => true
        ]);

        $this->validateOperationMetrics($operationId);
    }

    public function checkThresholds(array $thresholds): bool
    {
        $currentState = $this->getCurrentSystemState();

        foreach ($thresholds as $metric => $threshold) {
            if ($currentState[$metric] > $threshold) {
                $this->alerts->triggerThresholdAlert($metric, $currentState[$metric], $threshold);
                return false;
            }
        }

        return true;
    }

    public function recordMetrics(array $metrics): void
    {
        $this->metrics->record($metrics);
        $this->analyzeMetrics($metrics);
    }

    private function validateOperationMetrics(string $operationId): void
    {
        $metrics = $this->metrics->getMetrics($operationId);
        
        if ($metrics['duration'] > 5000) { // 5 seconds
            $this->alerts->triggerPerformanceAlert($operationId);
        }

        if ($metrics['memory_peak'] > 128 * 1024 * 1024) { // 128MB
            $this->alerts->triggerResourceAlert($operationId);
        }
    }

    private function getCurrentSystemState(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'cpu_usage' => $this->getCurrentCPUUsage(),
            'disk_usage' => disk_free_space('/'),
            'system_load' => sys_getloadavg()[0]
        ];
    }
}
