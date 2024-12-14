<?php

namespace App\Core\Monitoring;

class CriticalPerformanceMonitor {
    private MetricsCollector $metrics;
    private AlertService $alerts;
    private LogService $logger;

    // Critical thresholds
    private const CRITICAL_CPU = 80;
    private const CRITICAL_MEMORY = 85;
    private const CRITICAL_RESPONSE = 200; // ms

    public function monitor(): void {
        // System metrics
        $this->checkSystem();
        
        // Application metrics
        $this->checkApplication();
        
        // Resource usage
        $this->checkResources();
        
        // Record metrics
        $this->recordMetrics();
    }

    private function checkSystem(): void {
        $cpu = $this->metrics->getCPUUsage();
        $memory = $this->metrics->getMemoryUsage();

        if($cpu > self::CRITICAL_CPU) {
            $this->handleCritical('CPU usage critical: ' . $cpu . '%');
        }

        if($memory > self::CRITICAL_MEMORY) {
            $this->handleCritical('Memory usage critical: ' . $memory . '%');
        }
    }

    private function handleCritical(string $message): void {
        $this->alerts->sendCritical($message);
        $this->logger->critical($message);
        throw new CriticalPerformanceException($message);
    }

    private function recordMetrics(): void {
        $this->metrics->record([
            'timestamp' => time(),
            'cpu' => $this->metrics->getCPUUsage(),
            'memory' => $this->metrics->getMemoryUsage(),
            'response_time' => $this->metrics->getAverageResponseTime()
        ]);
    }
}
