<?php

namespace App\Core\Monitoring;

class MetricsCollector implements MetricsCollectorInterface
{
    public function handleRetrievalFailure(
        \Throwable $e,
        string $type,
        array $filters
    ): void {
        $this->alerts->triggerAlert([
            'type' => 'metrics_retrieval_failure',
            'metric_type' => $type,
            'filters' => $filters,
            'error' => $e->getMessage(),
            'severity' => 'ERROR'
        ]);
    }

    public function getAggregatedMetrics(string $type, array $period): array
    {
        try {
            $metrics = $this->store->getMetricsByPeriod($type, $period);
            return $this->processor->calculateAggregates($metrics, [
                'min', 'max', 'avg', 'median', 'p95', 'p99'
            ]);
        } catch (\Throwable $e) {
            $this->handleAggregationFailure($e, $type, $period);
            throw $e;
        }
    }

    public function trackCriticalMetrics(array $metrics): void
    {
        $operationId = uniqid('critical_', true);

        try {
            $processed = $this->processor->processCriticalMetrics($metrics);
            $this->validateCriticalMetrics($processed);
            $this->storeCriticalMetrics($processed, $operationId);
            $this->checkCriticalThresholds($processed);

        } catch (\Throwable $e) {
            $this->handleCriticalFailure($e, $metrics, $operationId);
            throw $e;
        }
    }

    protected function validateCriticalMetrics(array $metrics): void
    {
        foreach ($metrics as $metric => $value) {
            if (!$this->processor->validateCriticalMetric($metric, $value)) {
                throw new MetricsException("Invalid critical metric: {$metric}");
            }
        }
    }

    protected function storeCriticalMetrics(array $metrics, string $operationId): void
    {
        $this->store->storeCritical([
            'metrics' => $metrics,
            'timestamp' => time(),
            'operation_id' => $operationId
        ]);
    }

    protected function checkCriticalThresholds(array $metrics): void
    {
        foreach ($metrics as $metric => $value) {
            if (isset($this->thresholds['critical'][$metric])) {
                $this->evaluateCriticalThreshold($metric, $value);
            }
        }
    }

    protected function evaluateCriticalThreshold(string $metric, $value): void
    {
        $threshold = $this->thresholds['critical'][$metric];

        if ($this->isCriticalThresholdViolated($value, $threshold)) {
            $this->handleCriticalViolation($metric, $value, $threshold);
        }
    }

    protected function isCriticalThresholdViolated($value, array $threshold): bool
    {
        $violation = $this->isThresholdViolated($value, $threshold);
        return $violation && $threshold['critical'] ?? false;
    }

    protected function handleCriticalViolation(
        string $metric,
        $value,
        array $threshold
    ): void {
        $this->alerts->triggerCriticalAlert([
            'type' => 'critical_threshold_violation',
            'metric' => $metric,
            'value' => $value,
            'threshold' => $threshold,
            'timestamp' => time(),
            'severity' => 'CRITICAL'
        ]);
    }

    protected function handleCriticalFailure(
        \Throwable $e,
        array $metrics,
        string $operationId
    ): void {
        $this->alerts->triggerCriticalAlert([
            'type' => 'critical_metrics_failure',
            'error' => $e->getMessage(),
            'metrics' => $metrics,
            'operation_id' => $operationId,
            'severity' => 'CRITICAL'
        ]);
    }

    protected function handleAggregationFailure(
        \Throwable $e,
        string $type,
        array $period
    ): void {
        $this->alerts->triggerAlert([
            'type' => 'metrics_aggregation_failure',
            'metric_type' => $type,
            'period' => $period,
            'error' => $e->getMessage(),
            'severity' => 'ERROR'
        ]);
    }
}
