<?php

namespace App\Core\Notification\Analytics\Monitoring;

use Illuminate\Support\Facades\Redis;
use App\Core\Monitoring\MetricsCollector;
use App\Core\Notification\Analytics\Events\PerformanceThresholdExceeded;

class PerformanceMonitor
{
    private MetricsCollector $metrics;
    private array $thresholds;

    public function __construct(MetricsCollector $metrics)
    {
        $this->metrics = $metrics;
        $this->thresholds = config('analytics.performance.thresholds');
    }

    public function recordQueryExecution(string $queryType, float $duration)
    {
        $this->metrics->record("notification_analytics.query.{$queryType}", $duration);
        $this->checkQueryThreshold($queryType, $duration);
    }

    public function recordProcessingTime(string $operation, float $duration)
    {
        $this->metrics->record("notification_analytics.processing.{$operation}", $duration);
        $this->checkProcessingThreshold($operation, $duration);
    }

    public function recordCacheOperation(string $operation, bool $hit, float $duration)
    {
        $this->metrics->increment("notification_analytics.cache.{$operation}" . ($hit ? '.hit' : '.miss'));
        $this->metrics->record("notification_analytics.cache.{$operation}.duration", $duration);
    }

    public function getPerformanceMetrics(): array
    {
        return [
            'queries' => $this->metrics->getMetricsByPattern('notification_analytics.query.*'),
            'processing' => $this->metrics->getMetricsByPattern('notification_analytics.processing.*'),
            'cache' => $this->metrics->getMetricsByPattern('notification_analytics.cache.*')
        ];
    }

    private function checkQueryThreshold(string $queryType, float $duration)
    {
        $threshold = $this->thresholds['query'][$queryType] ?? $this->thresholds['query']['default'];
        
        if ($duration > $threshold) {
            event(new PerformanceThresholdExceeded('query', $queryType, $duration, $threshold));
        }
    }

    private function checkProcessingThreshold(string $operation, float $duration)
    {
        $threshold = $this->thresholds['processing'][$operation] ?? $this->thresholds['processing']['default'];
        
        if ($duration > $threshold) {
            event(new PerformanceThresholdExceeded('processing', $operation, $duration, $threshold));
        }
    }
}
