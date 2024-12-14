<?php

namespace App\Core\Monitoring;

class PerformanceMonitor
{
    private $metrics;
    private $alerts;
    private $logger;

    public const CRITICAL_CPU = 70;
    public const CRITICAL_MEMORY = 80;
    public const CRITICAL_RESPONSE = 200; // ms

    public function checkSystem(): void
    {
        // Critical system checks
        $cpu = $this->metrics->getCPUUsage();
        $memory = $this->metrics->getMemoryUsage();

        if ($cpu > self::CRITICAL_CPU) {
            $this->handleCritical("CPU usage critical: $cpu%");
        }

        if ($memory > self::CRITICAL_MEMORY) {
            $this->handleCritical("Memory usage critical: $memory%");
        }
    }

    public function trackOperation(string $id): array
    {
        return [
            'cpu' => $this->metrics->getCPUUsage(),
            'memory' => $this->metrics->getMemoryUsage(),
            'response_time' => $this->metrics->getOperationTime($id)
        ];
    }

    private function handleCritical(string $message): void
    {
        $this->alerts->sendCritical($message);
        $this->logger->emergency($message);
        throw new SystemCriticalException($message);
    }
}
