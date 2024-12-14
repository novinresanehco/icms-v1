<?php

namespace App\Core\Monitoring;

class CriticalMonitoringService
{
    protected $metrics;
    protected $logger;

    public function checkSystemHealth(): bool
    {
        // CPU usage check
        if ($this->metrics->getCpuUsage() > 70) {
            throw new PerformanceException('CPU usage critical');
        }

        // Memory check
        if ($this->metrics->getMemoryUsage() > 80) {
            throw new PerformanceException('Memory usage critical');
        }

        // Storage check 
        if ($this->metrics->getDiskUsage() > 85) {
            throw new StorageException('Disk usage critical');
        }

        return true;
    }

    public function logFailure(\Exception $e): void
    {
        $this->logger->critical('Operation failed', [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'time' => time()
        ]);
    }
}
