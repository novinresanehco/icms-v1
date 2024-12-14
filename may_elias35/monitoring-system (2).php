<?php

namespace App\Core\Monitoring;

class MetricsCollector
{
    private array $metrics = [];
    private AlertSystem $alerts;
    
    public function increment(string $metric, int $value = 1): void
    {
        $this->metrics[$metric] = ($this->metrics[$metric] ?? 0) + $value;
        
        if ($this->shouldAlert($metric)) {
            $this->alerts->metricAlert($metric, $this->metrics[$metric]);
        }
    }
    
    public function gauge(string $metric, float $value): void
    {
        $this->metrics[$metric] = $value;
        
        if ($this->exceedsThreshold($metric, $value)) {
            $this->alerts->thresholdAlert($metric, $value);
        }
    }
    
    public function track(string $operation, callable $callback): mixed
    {
        $start = microtime(true);
        $result = $callback();
        $duration = microtime(true) - $start;
        
        $this->gauge("$operation.duration", $duration);
        
        return $result;
    }
}

class AlertSystem
{
    private NotificationService $notifications;
    private LogManager $logger;
    
    public function criticalError(string $operation, \Throwable $error): void
    {
        $this->logger->critical("Operation failed: $operation", [
            'error' => $error->getMessage(),
            'trace' => $error->getTraceAsString()
        ]);
        
        $this->notifications->sendToTeam(
            "CRITICAL: Operation $operation failed",
            $this->formatError($error)
        );
    }
    
    public function performanceWarning(string $operation, array $metrics): void
    {
        $this->logger->warning("Performance issue in $operation", $metrics);
        
        if ($this->isCritical($metrics)) {
            $this->notifications->sendToTeam(
                "PERFORMANCE: Critical issue in $operation",
                $this->formatMetrics($metrics)
            );
        }
    }
}

class HealthCheck
{
    private MetricsCollector $metrics;
    
    public function checkSystem(): HealthStatus
    {
        return new HealthStatus([
            'cpu' => $this->checkCPU(),
            'memory' => $this->checkMemory(),
            'disk' => $this->checkDisk(),
            'services' => $this->checkServices()
        ]);
    }
    
    private function checkServices(): array
    {
        return [
            'database' => $this->pingDatabase(),
            'cache' => $this->pingCache(),
            'queue' => $this->pingQueue()
        ];
    }
}
