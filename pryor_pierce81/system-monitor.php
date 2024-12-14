<?php

namespace App\Core\Infrastructure;

class CriticalSystemMonitor
{
    private MetricsCollector $metrics;
    private AlertService $alerts;
    private Logger $logger;

    const CRITICAL_THRESHOLDS = [
        'cpu' => 70,
        'memory' => 80,
        'disk' => 85,
        'response_time' => 200
    ];

    public function monitorSystem(): void
    {
        try {
            // System metrics
            $metrics = $this->collectSystemMetrics();
            
            // Check against thresholds
            foreach ($metrics as $key => $value) {
                if ($value > self::CRITICAL_THRESHOLDS[$key]) {
                    $this->handleCriticalState($key, $value);
                }
            }

            // Store metrics
            $this->metrics->store($metrics);

        } catch (\Exception $e) {
            $this->handleMonitoringFailure($e);
        }
    }

    private function handleCriticalState(string $metric, float $value): void
    {
        $this->alerts->sendCritical("System {$metric} critical: {$value}");
        $this->logger->critical("System {$metric} exceeded threshold", [
            'metric' => $metric,
            'value' => $value,
            'threshold' => self::CRITICAL_THRESHOLDS[$metric],
            'timestamp' => time()
        ]);

        if ($this->isSystemCritical($metric, $value)) {
            throw new SystemCriticalException("System {$metric} critically exceeded");
        }
    }

    private function collectSystemMetrics(): array
    {
        return [
            'cpu' => $this->metrics->getCPUUsage(),
            'memory' => $this->metrics->getMemoryUsage(),
            'disk' => $this->metrics->getDiskUsage(),
            'response_time' => $this->metrics->getAverageResponseTime()
        ];
    }

    private function isSystemCritical(string $metric, float $value): bool
    {
        return $value > (self::CRITICAL_THRESHOLDS[$metric] * 1.2);
    }
}
