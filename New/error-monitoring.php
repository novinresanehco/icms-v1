<?php

namespace App\Core\Monitoring;

/**
 * Core monitoring and error handling system with comprehensive protection
 */
class SystemMonitor implements MonitoringInterface
{
    private MetricsCollector $metrics;
    private AlertSystem $alerts;
    private LogManager $logs;
    private ValidationService $validator;
    
    public function __construct(
        MetricsCollector $metrics,
        AlertSystem $alerts,
        LogManager $logs,
        ValidationService $validator
    ) {
        $this->metrics = $metrics;
        $this->alerts = $alerts;
        $this->logs = $logs;
        $this->validator = $validator;
    }

    public function monitorOperation(callable $operation, array $context): mixed
    {
        // Pre-operation validation and setup
        $this->validateContext($context);
        $monitoringId = $this->startMonitoring($context);
        $startTime = microtime(true);

        try {
            // Execute operation with full monitoring
            $result = $this->executeWithProtection($operation, $monitoringId);
            
            // Record success metrics
            $this->recordSuccess($monitoringId, $startTime);
            
            return $result;

        } catch (\Throwable $e) {
            // Handle and record failure
            $this->handleFailure($e, $monitoringId, $context);
            throw $e;
            
        } finally {
            // Ensure monitoring cleanup
            $this->stopMonitoring($monitoringId);
        }
    }

    protected function validateContext(array $context): void
    {
        if (!$this->validator->validateMonitoringContext($context)) {
            throw new MonitoringException('Invalid monitoring context');
        }
    }

    protected function startMonitoring(array $context): string
    {
        $monitoringId = $this->generateMonitoringId();
        
        $this->metrics->initializeMetrics($monitoringId, [
            'start_time' => microtime(true),
            'context' => $context,
            'resource_usage' => $this->captureResourceUsage()
        ]);

        return $monitoringId;
    }

    protected function executeWithProtection(callable $operation, string $monitoringId): mixed
    {
        return $this->metrics->trackOperation($monitoringId, function() use ($operation) {
            return $operation();
        });
    }

    protected function recordSuccess(string $monitoringId, float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        
        $this->metrics->recordMetrics($monitoringId, [
            'duration' => $duration,
            'status' => 'success',
            'resource_usage' => $this->captureResourceUsage()
        ]);

        // Alert if performance thresholds exceeded
        if ($duration > $this->getThreshold('duration')) {
            $this->alerts->performanceWarning($monitoringId, $duration);
        }
    }

    protected function handleFailure(\Throwable $e, string $monitoringId, array $context): void
    {
        // Record failure metrics
        $this->metrics->recordFailure($monitoringId, [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'context' => $context
        ]);

        // Log detailed error information
        $this->logs->critical('Operation failed', [
            'monitoring_id' => $monitoringId,
            'exception' => $e,
            'context' => $context,
            'system_state' => $this->captureSystemState()
        ]);

        // Send critical alerts
        $this->alerts->criticalError($monitoringId, $e, $context);
    }

    protected function stopMonitoring(string $monitoringId): void
    {
        $this->metrics->finalizeMetrics($monitoringId, [
            'end_time' => microtime(true),
            'final_state' => $this->captureSystemState()
        ]);
    }

    protected function captureResourceUsage(): array
    {
        return [
            'memory' => memory_get_usage(true),
            'cpu' => sys_getloadavg()[0],
            'time' => microtime(true)
        ];
    }

    protected function captureSystemState(): array
    {
        return [
            'resource_usage' => $this->captureResourceUsage(),
            'error_state' => error_get_last(),
            'active_connections' => $this->getActiveConnections(),
            'queue_size' => $this->getQueueSize()
        ];
    }

    protected function generateMonitoringId(): string
    {
        return uniqid('monitor_', true);
    }

    protected function getThreshold(string $metric): float
    {
        return match($metric) {
            'duration' => 1.0, // 1 second
            'memory' => 128 * 1024 * 1024, // 128MB
            'cpu' => 0.8, // 80% CPU
            default => throw new \InvalidArgumentException("Unknown metric: {$metric}")
        };
    }

    private function getActiveConnections(): int
    {
        // Implementation depends on server setup
        return 0; 
    }

    private function getQueueSize(): int
    {
        // Implementation depends on queue system
        return 0;
    }
}
