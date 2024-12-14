<?php

namespace App\Core\Monitoring;

class PerformanceMonitoringSystem implements MonitoringInterface 
{
    private MetricsCollector $metrics;
    private ThresholdManager $thresholds;
    private AlertDispatcher $alerts;
    private PerformanceLogger $logger;
    private ResourceMonitor $resources;

    public function __construct(
        MetricsCollector $metrics,
        ThresholdManager $thresholds,
        AlertDispatcher $alerts,
        PerformanceLogger $logger,
        ResourceMonitor $resources
    ) {
        $this->metrics = $metrics;
        $this->thresholds = $thresholds;
        $this->alerts = $alerts;
        $this->logger = $logger;
        $this->resources = $resources;
    }

    public function monitorOperation(Operation $operation): void 
    {
        $monitoringId = $this->startMonitoring($operation);
        
        try {
            $startTime = microtime(true);
            $startMemory = memory_get_usage(true);

            $result = $operation->execute();

            $this->recordMetrics($monitoringId, [
                'execution_time' => microtime(true) - $startTime,
                'memory_usage' => memory_get_usage(true) - $startMemory,
                'peak_memory' => memory_get_peak_usage(true),
                'cpu_usage' => $this->resources->getCpuUsage()
            ]);

            $this->validatePerformance($monitoringId);

        } catch (\Exception $e) {
            $this->handleMonitoringFailure($monitoringId, $e);
            throw $e;
        } finally {
            $this->stopMonitoring($monitoringId);
        }
    }

    private function startMonitoring(Operation $operation): string 
    {
        $monitoringId = Str::uuid();

        $this->metrics->initializeMetrics($monitoringId, [
            'operation_type' => $operation->getType(),
            'start_time' => microtime(true),
            'initial_memory' => memory_get_usage(true),
            'initial_cpu' => $this->resources->getCpuUsage()
        ]);

        return $monitoringId;
    }

    private function validatePerformance(string $monitoringId): void 
    {
        $metrics = $this->metrics->getMetrics($monitoringId);
        
        $violations = $this->thresholds->checkViolations($metrics);
        
        if (!empty($violations)) {
            foreach ($violations as $violation) {
                $this->handleViolation($monitoringId, $violation);
            }
        }
    }

    private function handleViolation(string $monitoringId, Violation $violation): void 
    {
        $this->logger->logViolation($monitoringId, $violation);

        if ($violation->isCritical()) {
            $this->alerts->dispatchCriticalAlert([
                'monitoring_id' => $monitoringId,
                'violation' => $violation->toArray(),
                'metrics' => $this->metrics->getMetrics($monitoringId),
                'timestamp' => now()
            ]);
        }
    }

    private function handleMonitoringFailure(string $monitoringId, \Exception $e): void 
    {
        $this->logger->logFailure($monitoringId, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'metrics' => $this->metrics->getMetrics($monitoringId)
        ]);

        $this->alerts->dispatchCriticalAlert([
            'type' => 'monitoring_failure',
            'monitoring_id' => $monitoringId,
            'error' => $e->getMessage(),
            'timestamp' => now()
        ]);
    }

    private function stopMonitoring(string $monitoringId): void 
    {
        $this->metrics->finalizeMetrics($monitoringId, [
            'end_time' => microtime(true),
            'final_memory' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'final_cpu' => $this->resources->getCpuUsage()
        ]);

        $this->logger->logMetrics($monitoringId, $this->metrics->getMetrics($monitoringId));
    }
}
