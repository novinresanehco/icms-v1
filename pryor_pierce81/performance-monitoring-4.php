<?php

namespace App\Core\Monitoring\Performance;

class PerformanceMonitor {
    private MetricsCollector $metricsCollector;
    private PerformanceAnalyzer $analyzer;
    private ThresholdManager $thresholdManager;
    private AlertDispatcher $alertDispatcher;

    public function __construct(
        MetricsCollector $metricsCollector,
        PerformanceAnalyzer $analyzer,
        ThresholdManager $thresholdManager,
        AlertDispatcher $alertDispatcher
    ) {
        $this->metricsCollector = $metricsCollector;
        $this->analyzer = $analyzer;
        $this->thresholdManager = $thresholdManager;
        $this->alertDispatcher = $alertDispatcher;
    }

    public function recordMetric(string $key, float $value, array $tags = []): void 
    {
        $metric = new PerformanceMetric($key, $value, $tags, microtime(true));
        
        $this->metricsCollector->collect($metric);
        
        if ($this->thresholdManager->isThresholdExceeded($metric)) {
            $this->handleThresholdViolation($metric);
        }
    }

    public function startOperation(string $operation): OperationContext 
    {
        return new OperationContext($operation, microtime(true));
    }

    public function endOperation(OperationContext $context): void 
    {
        $duration = microtime(true) - $context->getStartTime();
        
        $this->recordMetric(
            "operation.{$context->getName()}.duration",
            $duration,
            ['operation' => $context->getName()]
        );
    }

    public function analyzePerformance(TimeRange $range): PerformanceReport 
    {
        $metrics = $this->metricsCollector->getMetrics($range);
        return $this->analyzer->analyze($metrics);
    }

    private function handleThresholdViolation(PerformanceMetric $metric): void 
    {
        $alert = new PerformanceAlert(
            $metric,
            $this->thresholdManager->getThreshold($metric->getKey()),
            microtime(true)
        );

        $this->alertDispatcher->dispatch($alert);
    }
}

class PerformanceMetric {
    private string $key;
    private float $value;
    private array $tags;
    private float $timestamp;

    public function __construct(string $key, float $value, array $tags, float $timestamp) 
    {
        $this->key = $key;
        $this->value = $value;
        $this->tags = $tags;
        $this->timestamp = $timestamp;
    }

    public function getKey(): string 
    {
        return $this->key;
    }

    public function getValue(): float 
    {
        return $this->value;
    }

    public function getTags(): array 
    {
        return $this->tags;
    }

    public function getTimestamp(): float 
    {
        return $this->timestamp;
    }
}

class OperationContext {
    private string $name;
    private float $startTime;
    private array $metadata;

    public function __construct(string $name, float $startTime, array $metadata = []) 
    {
        $this->name = $name;
        $this->startTime = $startTime;
        $this->metadata = $metadata;
    }

    public function getName(): string 
    {
        return $this->name;
    }

    public function getStartTime(): float 
    {
        return $this->startTime;
    }

    public function getMetadata(): array 
    {
        return $this->metadata;
    }
}

class ThresholdManager {
    private array $thresholds;

    public function __construct(array $thresholds) 
    {
        $this->thresholds = $thresholds;
    }

    public function isThresholdExceeded(PerformanceMetric $metric): bool 
    {
        $threshold = $this->getThreshold($metric->getKey());
        return $threshold !== null && $metric->getValue() > $threshold->getValue();
    }

    public function getThreshold(string $metricKey): ?Threshold 
    {
        return $this->thresholds[$metricKey] ?? null;
    }
}

class Threshold {
    private string $metricKey;
    private float $value;
    private string $severity;

    public function __construct(string $metricKey, float $value, string $severity) 
    {
        $this->metricKey = $metricKey;
        $this->value = $value;
        $this->severity = $severity;
    }

    public function getValue(): float 
    {
        return $this->value;
    }

    public function getSeverity(): string 
    {
        return $this->severity;
    }
}

class PerformanceAlert {
    private PerformanceMetric $metric;
    private Threshold $threshold;
    private float $timestamp;

    public function __construct(PerformanceMetric $metric, Threshold $threshold, float $timestamp) 
    {
        $this->metric = $metric;
        $this->threshold = $threshold;
        $this->timestamp = $timestamp;
    }

    public function getMetric(): PerformanceMetric 
    {
        return $this->metric;
    }

    public function getThreshold(): Threshold 
    {
        return $this->threshold;
    }

    public function getTimestamp(): float 
    {
        return $this->timestamp;
    }
}

class TimeRange {
    private float $start;
    private float $end;

    public function __construct(float $start, float $end) 
    {
        if ($end <= $start) {
            throw new \InvalidArgumentException('End time must be greater than start time');
        }
        
        $this->start = $start;
        $this->end = $end;
    }

    public function getStart(): float 
    {
        return $this->start;
    }

    public function getEnd(): float 
    {
        return $this->end;
    }

    public function contains(float $timestamp): bool 
    {
        return $timestamp >= $this->start && $timestamp <= $this->end;
    }
}
