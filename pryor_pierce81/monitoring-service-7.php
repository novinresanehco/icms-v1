<?php

namespace App\Core\Monitoring;

class MonitoringService
{
    private LoggerInterface $logger;
    private MetricsCollector $metrics;
    private AlertService $alerts;

    private array $requestData = [];

    public function startRequest(): void
    {
        $this->requestData = [
            'start_time' => microtime(true),
            'memory_start' => memory_get_usage(),
        ];
    }

    public function trackSuccess(): void
    {
        $this->recordMetrics();
    }

    public function trackFailure(\Exception $e): void
    {
        $this->logger->error('Operation failed', [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'metrics' => $this->collectMetrics()
        ]);

        if ($this->isCriticalError($e)) {
            $this->alerts->sendCriticalAlert($e);
        }
    }

    private function recordMetrics(): void
    {
        $metrics = $this->collectMetrics();
        
        // Check performance thresholds
        if ($metrics['response_time'] > 200) {
            $this->alerts->sendPerformanceAlert($metrics);
        }

        if ($metrics['memory_used'] > 64 * 1024 * 1024) { // 64MB
            $this->alerts->sendMemoryAlert($metrics);
        }

        $this->metrics->record($metrics);
    }

    private function collectMetrics(): array
    {
        return [
            'response_time' => microtime(true) - $this->requestData['start_time'],
            'memory_used' => memory_get_usage() - $this->requestData['memory_start'],
            'peak_memory' => memory_get_peak_usage(),
            'timestamp' => time()
        ];
    }

    private function isCriticalError(\Exception $e): bool
    {
        return $e instanceof SecurityException ||
               $e instanceof DatabaseException ||
               $e instanceof SystemException;
    }
}
