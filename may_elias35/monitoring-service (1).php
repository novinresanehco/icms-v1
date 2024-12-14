<?php

namespace App\Core\Monitoring;

use App\Core\Audit\AuditLogger;
use Illuminate\Support\Facades\Cache;

class MonitoringService implements MonitorInterface
{
    private AuditLogger $audit;
    private array $thresholds;
    private array $criticalMetrics;

    public function __construct(
        AuditLogger $audit,
        array $thresholds,
        array $criticalMetrics
    ) {
        $this->audit = $audit;
        $this->thresholds = $thresholds;
        $this->criticalMetrics = $criticalMetrics;
    }

    public function monitorOperation(
        Operation $operation,
        callable $execution
    ): OperationResult {
        $monitoringId = $this->startMonitoring($operation);
        
        try {
            // Pre-execution check
            $this->validateSystemState();
            
            // Monitor execution
            $result = $this->executeWithMonitoring(
                $execution,
                $monitoringId
            );
            
            // Post-execution validation
            $this->validateOperationResult($result);
            
            $this->endMonitoring($monitoringId, true);
            return $result;
            
        } catch (\Exception $e) {
            $this->handleMonitoringFailure($e, $monitoringId);
            throw $e;
        }
    }

    public function trackPerformance(string $metric, $value): void
    {
        $this->recordMetric($metric, $value);
        
        if ($this->isMetricCritical($metric)) {
            $this->validateCriticalMetric($metric, $value);
        }
    }

    public function getSystemHealth(): SystemHealth
    {
        return new SystemHealth(
            $this->collectHealthMetrics(),
            $this->validateHealthStatus()
        );
    }

    private function startMonitoring(Operation $operation): string
    {
        $monitoringId = $this->generateMonitoringId();
        
        $this->recordOperationStart($monitoringId, $operation);
        
        return $monitoringId;
    }

    private function validateSystemState(): void
    {
        $metrics = $this->collectSystemMetrics();
        
        foreach ($metrics as $metric => $value) {
            if ($this->exceedsThreshold($metric, $value)) {
                throw new MonitoringException(
                    "System metric {$metric} exceeds threshold"
                );
            }
        }
    }

    private function executeWithMonitoring(
        callable $execution,
        string $monitoringId
    ): OperationResult {
        $startTime = microtime(true);
        
        try {
            $result = $execution();
            
            $this->recordExecutionMetrics(
                $monitoringId,
                microtime(true) - $startTime
            );
            
            return $result;
            
        } catch (\Exception $e) {
            $this->recordExecutionFailure($monitoringId, $e);
            throw $e;
        }
    }

    private function validateOperationResult(OperationResult $result): void
    {
        if (!$result->meetsPerformanceCriteria()) {
            throw new PerformanceException('Operation performance below threshold');
        }

        if (!$result->isSystemStateValid()) {
            throw new SystemStateException('Invalid system state after operation');
        }
    }

    private function handleMonitoringFailure(
        \Exception $e,
        string $monitoringId
    ): void {
        $this->audit->logMonitoringFailure($e, [
            'monitoring_id' => $monitoringId,
            'timestamp' => now(),
            'system_state' => $this->captureSystemState()
        ]);
        
        $this->endMonitoring($monitoringId, false);
    }

    private function recordMetric(string $metric, $value): void
    {
        Cache::put(
            "metrics:{$metric}",
            [
                'value' => $value,
                'timestamp' => now(),
                'context' => $this->getMetricContext()
            ],
            now()->addMinutes(60)
        );
    }

    private function validateCriticalMetric(string $metric, $value): void
    {
        if ($this->exceedsThreshold($metric, $value)) {
            $this->handleCriticalMetricViolation($metric, $value);
        }
    }

    private function exceedsThreshold(string $metric, $value): bool
    {
        return $value > ($this->thresholds[$metric] ?? PHP_FLOAT_MAX);
    }

    private function handleCriticalMetricViolation(
        string $metric,
        $value
    ): void {
        $this->audit->logCriticalMetricViolation([
            'metric' => $metric,
            'value' => $value,
            'threshold' => $this->thresholds[$metric],
            'timestamp' => now()
        ]);
        
        $this->triggerMetricAlert($metric, $value);
    }

    private function collectHealthMetrics(): array
    {
        return [
            'cpu_usage' => sys_getloadavg()[0],
            'memory_usage' => memory_get_usage(true),
            'disk_usage' => disk_free_space('/'),
            'connection_count' => $this->getConnectionCount(),
            'queue_size' => $this->getQueueSize()
        ];
    }

    private function validateHealthStatus(): bool
    {
        $metrics = $this->collectHealthMetrics();
        
        foreach ($metrics as $metric => $value) {
            if ($this->exceedsThreshold($metric, $value)) {
                return false;
            }
        }
        
        return true;
    }

    private function captureSystemState(): array
    {
        return [
            'metrics' => $this->collectHealthMetrics(),
            'status' => $this->validateHealthStatus(),
            'timestamp' => now()
        ];
    }
}
