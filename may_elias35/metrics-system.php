```php
namespace App\Core\Metrics;

class MetricsCollector implements MetricsInterface
{
    private StorageManager $storage;
    private SecurityManager $security;
    private AlertSystem $alerts;

    public function increment(string $metric, int $value = 1): void
    {
        $this->validateMetric($metric);
        
        $this->storage->increment(
            $this->security->sanitizeMetricKey($metric), 
            $value,
            ['timestamp' => now()]
        );

        $this->checkThreshold($metric);
    }

    public function gauge(string $metric, float $value): void
    {
        $this->validateMetric($metric);
        
        $this->storage->gauge(
            $this->security->sanitizeMetricKey($metric),
            $value,
            ['timestamp' => now()]
        );

        if ($this->exceedsThreshold($metric, $value)) {
            $this->alerts->metricThresholdExceeded($metric, $value);
        }
    }

    public function startOperation(MonitoringContext $context): void
    {
        $this->storage->startTransaction([
            'operation' => $context->operation,
            'trace_id' => $context->trace_id,
            'start_time' => $context->start_time
        ]);
    }

    public function endOperation(MonitoringContext $context, array $metrics): void
    {
        $this->storage->endTransaction(
            $context->trace_id,
            $this->security->sanitizeMetrics($metrics)
        );

        $this->analyzeOperationMetrics($context, $metrics);
    }

    private function analyzeOperationMetrics(MonitoringContext $context, array $metrics): void
    {
        if ($this->detectAnomaly($metrics)) {
            $this->alerts->performanceAnomaly($context, $metrics);
        }
    }

    private function detectAnomaly(array $metrics): bool
    {
        return $metrics['duration'] > config('metrics.thresholds.anomaly') ||
               $metrics['memory'] > config('metrics.thresholds.memory');
    }
}
```
