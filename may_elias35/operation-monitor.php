<?php

namespace App\Core\Security;

use App\Core\Interfaces\MonitoringInterface;

class OperationMonitor implements MonitoringInterface 
{
    private CriticalOperation $operation;
    private MetricsCollector $metrics;
    private SecurityConfig $config;
    private AuditLogger $logger;

    private array $checkpoints = [];
    private float $startTime;
    private array $resourceUsage = [];

    public function __construct(
        CriticalOperation $operation,
        MetricsCollector $metrics,
        SecurityConfig $config,
        AuditLogger $logger
    ) {
        $this->operation = $operation;
        $this->metrics = $metrics;
        $this->config = $config;
        $this->logger = $logger;
    }

    public function execute(callable $operation)
    {
        $this->startMonitoring();

        try {
            // Execute operation with continuous monitoring
            $result = $this->monitorExecution($operation);

            // Verify execution metrics against thresholds
            $this->verifyExecutionMetrics();

            return $result;

        } finally {
            $this->stopMonitoring();
        }
    }

    private function startMonitoring(): void
    {
        $this->startTime = microtime(true);
        $this->resourceUsage['start'] = $this->captureResourceUsage();
        $this->addCheckpoint('monitoring_started');
    }

    private function monitorExecution(callable $operation)
    {
        // Set up real-time monitoring hooks
        $this->registerMonitoringHooks();

        // Execute with monitoring
        $result = $operation();

        // Record execution metrics
        $this->recordExecutionMetrics();

        return $result;
    }

    private function verifyExecutionMetrics(): void
    {
        $duration = microtime(true) - $this->startTime;
        $memoryUsage = memory_get_peak_usage(true);
        
        // Verify against configured thresholds
        if ($duration > $this->config->getMaxDuration()) {
            throw new PerformanceException('Operation exceeded maximum duration');
        }

        if ($memoryUsage > $this->config->getMaxMemory()) {
            throw new ResourceException('Operation exceeded memory limit');
        }

        $this->addCheckpoint('metrics_verified');
    }

    private function stopMonitoring(): void
    {
        $this->resourceUsage['end'] = $this->captureResourceUsage();
        $this->addCheckpoint('monitoring_stopped');
        
        // Record final metrics
        $this->metrics->record([
            'operation_id' => $this->operation->getId(),
            'duration' => microtime(true) - $this->startTime,
            'resource_usage' => $this->resourceUsage,
            'checkpoints' => $this->checkpoints
        ]);
    }

    public function recordFailure(\Exception $e): void
    {
        $this->addCheckpoint('operation_failed');
        
        $this->logger->logError('Operation failed', [
            'operation' => $this->operation->getId(),
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'metrics' => [
                'duration' => microtime(true) - $this->startTime,
                'resource_usage' => $this->resourceUsage,
                'checkpoints' => $this->checkpoints
            ]
        ]);
    }

    private function registerMonitoringHooks(): void
    {
        // Register memory usage monitoring
        register_tick_function(function() {
            $this->checkResourceUsage();
        });
    }

    private function checkResourceUsage(): void
    {
        $current = $this->captureResourceUsage();
        
        if ($current['memory'] > $this->config->getMaxMemory()) {
            throw new ResourceException('Memory limit exceeded during execution');
        }

        if ($current['cpu'] > $this->config->getMaxCpu()) {
            throw new ResourceException('CPU usage limit exceeded');
        }
    }

    private function captureResourceUsage(): array
    {
        return [
            'memory' => memory_get_usage(true),
            'cpu' => sys_getloadavg()[0],
            'time' => microtime(true)
        ];
    }

    private function addCheckpoint(string $name): void
    {
        $this->checkpoints[] = [
            'name' => $name,
            'time' => microtime(true),
            'memory' => memory_get_usage(true)
        ];
    }
}
