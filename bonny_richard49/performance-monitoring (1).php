<?php

namespace App\Core\Infrastructure;

class PerformanceMonitor
{
    private MetricsCollector $metrics;
    private AlertSystem $alerts;
    private ThresholdManager $thresholds;
    private LogManager $logger;

    public function __construct(
        MetricsCollector $metrics,
        AlertSystem $alerts,
        ThresholdManager $thresholds,
        LogManager $logger
    ) {
        $this->metrics = $metrics;
        $this->alerts = $alerts;
        $this->thresholds = $thresholds;
        $this->logger = $logger;
    }

    public function startOperation(): string
    {
        $operationId = uniqid('perf_', true);
        
        // Initialize metric collection
        $this->metrics->startCollection($operationId);
        
        // Capture baseline metrics
        $this->captureBaseline($operationId);
        
        return $operationId;
    }

    public function trackExecution(callable $operation): mixed
    {
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);
        
        try {
            $result = $operation();
            
            // Track execution metrics
            $this->trackExecutionMetrics(
                microtime(true) - $startTime,
                memory_get_usage(true) - $startMemory
            );
            
            return $result;
            
        } catch (\Throwable $e) {
            // Log performance metrics on failure
            $this->logFailureMetrics($e, $startTime, $startMemory);
            throw $e;
        }
    }

    public function checkSystemHealth(): bool
    {
        $metrics = $this->getSystemMetrics();
        
        // Check critical thresholds
        foreach ($this->thresholds->getCriticalThresholds() as $metric => $threshold) {
            if ($metrics[$metric] > $threshold) {
                $this->alerts->triggerCriticalAlert($metric, $metrics[$metric]);
                return false;
            }
        }
        
        return true;
    }

    public function getSystemMetrics(): array
    {
        return [
            'cpu_usage' => $this->metrics->getCpuUsage(),
            'memory_usage' => $this->metrics->getMemoryUsage(),
            'disk_usage' => $this->metrics->getDiskUsage(),
            'network_latency' => $this->metrics->getNetworkLatency(),
            'response_times' => $this->metrics->getResponseTimes(),
            'error_rates' => $this->metrics->getErrorRates()
        ];
    }

    public function endOperation(string $operationId): void
    {
        // Collect final metrics
        $finalMetrics = $this->metrics->collectFinalMetrics($operationId);
        
        // Analyze performance
        $this->analyzePerformance($finalMetrics);
        
        // Log metrics
        $this->logger->info('Operation metrics', [
            'operation_id' => $operationId,
            'metrics' => $finalMetrics
        ]);
        
        // Cleanup
        $this->metrics->clearMetrics($operationId);
    }

    private function captureBaseline(string $operationId): void
    {
        $baseline = [
            'cpu_usage' => $this->metrics->getCpuUsage(),
            'memory_usage' => $this->metrics->getMemoryUsage(),
            'timestamp' => microtime(true)
        ];
        
        $this->metrics->saveBaseline($operationId, $baseline);
    }

    private function trackExecutionMetrics(float $duration, int $memoryUsed): void
    {
        if ($duration > $this->thresholds->getMaxExecutionTime()) {
            $this->alerts->triggerPerformanceAlert('execution_time', $duration);
        }
        
        if ($memoryUsed > $this->thresholds->getMaxMemoryUsage()) {
            $this->alerts->triggerPerformanceAlert('memory_usage', $memoryUsed);
        }
    }

    private function analyzePerformance(array $metrics): void
    {
        foreach ($metrics as $metric => $value) {
            if ($this->thresholds->isThresholdExceeded($metric, $value)) {
                $this->alerts->triggerThresholdAlert($metric, $value);
            }
        }
    }
}
