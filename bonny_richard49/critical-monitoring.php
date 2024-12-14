<?php

namespace App\Core\Monitoring;

class CriticalMonitoringSystem implements MonitoringInterface 
{
    private MetricsCollector $metrics;
    private ThresholdManager $thresholds;
    private AlertSystem $alerts;
    private LogManager $logger;

    public function monitorOperation(string $type, callable $operation)
    {
        $monitorId = $this->startMonitoring($type);

        try {
            // Pre-execution checks
            $this->verifySystemState($monitorId);
            
            // Monitor execution
            $result = $this->executeWithMonitoring($monitorId, $operation);
            
            // Verify post-execution state
            $this->verifyExecutionMetrics($monitorId);
            
            return $result;
            
        } catch (\Exception $e) {
            $this->handleMonitoringFailure($monitorId, $e);
            throw new MonitoringException('Monitoring failed', 0, $e);
        }
    }

    private function startMonitoring(string $type): string
    {
        $monitorId = uniqid('monitor_', true);
        
        $this->metrics->initializeMetrics($monitorId, [
            'type' => $type,
            'start_time' => microtime(true),
            'memory_start' => memory_get_usage(true)
        ]);
        
        return $monitorId;
    }

    private function verifySystemState(string $monitorId): void
    {
        $state = $this->metrics->getSystemState();

        if (!$this->thresholds->validateMemoryUsage($state['memory'])) {
            throw new ThresholdException('Memory threshold exceeded');
        }

        if (!$this->thresholds->validateCpuUsage($state['cpu'])) {
            throw new ThresholdException('CPU threshold exceeded');
        }

        if (!$this->thresholds->validateDiskUsage($state['disk'])) {
            throw new ThresholdException('Disk threshold exceeded');
        }
    }

    private function executeWithMonitoring(string $monitorId, callable $operation)
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        try {
            $result = $operation();

            $this->metrics->recordMetrics($monitorId, [
                'execution_time' => microtime(true) - $startTime,
                'memory_used' => memory_get_usage(true) - $startMemory,
                'peak_memory' => memory_get_peak_usage(true)
            ]);

            return $result;

        } catch (\Exception $e) {
            $this->metrics->recordFailure($monitorId, [
                'error' => $e->getMessage(),
                'execution_time' => microtime(true) - $startTime
            ]);
            throw $e;
        }
    }

    private function verifyExecutionMetrics(string $monitorId): void
    {
        $metrics = $this->metrics->getMetrics($monitorId);

        if (!$this->thresholds->validateExecutionTime($metrics['execution_time'])) {
            throw new ThresholdException('Execution time threshold exceeded');
        }

        if (!$this->thresholds->validateMemoryUsage($metrics['memory_used'])) {
            throw new ThresholdException('Memory usage threshold exceeded');
        }
    }

    private function handleMonitoringFailure(string $monitorId, \Exception $e): void
    {
        // Log failure
        $this->logger->logFailure($monitorId, [
            'error' => $e->getMessage(),
            'metrics' => $this->metrics->getMetrics($monitorId)
        ]);

        // Send alerts
        $this->alerts->sendCriticalAlert($monitorId, $e->getMessage());

        // Execute recovery
        $this->executeRecovery($monitorId);
    }

    private function executeRecovery(string $monitorId): void
    {
        // Reset metrics
        $this->metrics->resetMetrics($monitorId);

        // Reset thresholds
        $this->thresholds->resetThresholds();

        // Clear monitoring state
        $this->clearMonitoringState($monitorId);
    }
}

interface MonitoringInterface
{
    public function monitorOperation(string $type, callable $operation);
}

class MetricsCollector 
{
    private array $metrics = [];

    public function initializeMetrics(string $monitorId, array $initialData): void
    {
        $this->metrics[$monitorId] = $initialData;
    }

    public function recordMetrics(string $monitorId, array $metrics): void
    {
        $this->metrics[$monitorId]['measurements'][] = array_merge(
            $metrics,
            ['timestamp' => microtime(true)]
        );
    }

    public function getMetrics(string $monitorId): array
    {
        return $this->metrics[$monitorId] ?? [];
    }

    public function resetMetrics(string $monitorId): void
    {
        unset($this->metrics[$monitorId]);
    }
}

class ThresholdManager
{
    private array $thresholds;

    public function validateMemoryUsage(int $usage): bool
    {
        return $usage <= $this->thresholds['max_memory'];
    }

    public function validateCpuUsage(float $usage): bool
    {
        return $usage <= $this->thresholds['max_cpu'];
    }

    public function validateExecutionTime(float $time): bool
    {
        return $time <= $this->thresholds['max_execution_time'];
    }
}

class MonitoringException extends \Exception {}
class ThresholdException extends \Exception {}
