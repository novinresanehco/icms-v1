<?php

namespace App\Core\Monitoring;

class PerformanceMonitor
{
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private ThresholdManager $thresholds;

    public function trackOperation(string $operation, callable $callback): mixed
    {
        $start = microtime(true);
        
        try {
            $result = $callback();
            
            $this->recordMetrics(
                $operation,
                microtime(true) - $start,
                true
            );
            
            return $result;
            
        } catch (\Throwable $e) {
            $this->recordMetrics(
                $operation,
                microtime(true) - $start,
                false
            );
            
            throw $e;
        }
    }

    public function checkThresholds(): void
    {
        $metrics = $this->metrics->getCurrentMetrics();
        
        foreach ($metrics as $metric => $value) {
            if ($this->thresholds->isExceeded($metric, $value)) {
                $this->alerts->trigger(
                    new ThresholdExceeded($metric, $value)
                );
            }
        }
    }

    private function recordMetrics(
        string $operation,
        float $duration,
        bool $success
    ): void {
        $this->metrics->record([
            'operation' => $operation,
            'duration' => $duration,
            'success' => $success,
            'memory' => memory_get_peak_usage(true),
            'timestamp' => time()
        ]);
    }
}

class MetricsCollector
{
    private MetricsStore $store;

    public function record(array $metrics): void
    {
        $this->store->save($metrics);
    }

    public function getCurrentMetrics(): array
    {
        return $this->store->getRecent();
    }
}

class ThresholdManager
{
    private array $thresholds = [
        'response_time' => 200, // milliseconds
        'memory_usage' => 128 * 1024 * 1024, // 128MB
        'error_rate' => 0.01 // 1%
    ];

    public function isExceeded(string $metric, $value): bool
    {
        return $value > ($this->thresholds[$metric] ?? PHP_FLOAT_MAX);
    }
}

class AlertManager
{
    private NotificationService $notifications;
    private EventDispatcher $events;

    public function trigger(ThresholdExceeded $event): void
    {
        $this->events->dispatch($event);
        $this->notifications->send(
            new PerformanceAlert($event)
        );
    }
}
