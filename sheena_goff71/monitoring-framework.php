<?php

namespace App\Core\Monitoring;

use Illuminate\Support\Facades\{Cache, Log, Event};
use App\Core\Interfaces\{
    MonitoringInterface,
    AlertInterface,
    MetricsInterface,
    ValidationInterface
};
use App\Core\Events\{SystemAlert, ThresholdBreached, PerformanceDegraded};
use App\Core\Exceptions\{MonitoringException, ValidationException, ThresholdException};

class SystemMonitor implements MonitoringInterface
{
    protected AlertInterface $alert;
    protected MetricsInterface $metrics;
    protected ValidationInterface $validator;
    protected array $thresholds;
    protected array $monitors = [];
    protected array $criticalMetrics = [];

    public function __construct(
        AlertInterface $alert,
        MetricsInterface $metrics,
        ValidationInterface $validator,
        array $config
    ) {
        $this->alert = $alert;
        $this->metrics = $metrics;
        $this->validator = $validator;
        $this->thresholds = $config['thresholds'];
        $this->initializeMonitors();
    }

    public function startOperation(string $operationId, array $context = []): void
    {
        $monitor = [
            'id' => $operationId,
            'start_time' => microtime(true),
            'context' => $context,
            'metrics' => [],
            'alerts' => [],
            'status' => 'active'
        ];

        $this->monitors[$operationId] = $monitor;
    }

    public function trackMetric(string $operationId, string $metric, $value): void
    {
        if (!isset($this->monitors[$operationId])) {
            throw new MonitoringException("Unknown operation: {$operationId}");
        }

        $this->monitors[$operationId]['metrics'][$metric] = [
            'value' => $value,
            'timestamp' => microtime(true)
        ];

        $this->validateMetric($operationId, $metric, $value);
        $this->updateCriticalMetrics($metric, $value);
    }

    public function endOperation(string $operationId): array
    {
        if (!isset($this->monitors[$operationId])) {
            throw new MonitoringException("Unknown operation: {$operationId}");
        }

        $monitor = $this->monitors[$operationId];
        $monitor['end_time'] = microtime(true);
        $monitor['duration'] = $monitor['end_time'] - $monitor['start_time'];
        $monitor['status'] = 'completed';

        $this->validateOperation($monitor);
        $this->persistMetrics($monitor);

        unset($this->monitors[$operationId]);
        return $monitor;
    }

    public function getCriticalMetrics(): array
    {
        return $this->criticalMetrics;
    }

    public function getSystemStatus(): array
    {
        return [
            'active_operations' => count($this->monitors),
            'critical_metrics' => $this->criticalMetrics,
            'thresholds' => $this->getThresholdStatus(),
            'alerts' => $this->getActiveAlerts(),
            'performance' => $this->getPerformanceMetrics()
        ];
    }

    protected function initializeMonitors(): void
    {
        foreach ($this->thresholds as $metric => $config) {
            $this->criticalMetrics[$metric] = [
                'current' => 0,
                'peak' => 0,
                'threshold' => $config['critical'],
                'status' => 'normal'
            ];
        }
    }

    protected function validateMetric(string $operationId, string $metric, $value): void
    {
        if (!isset($this->thresholds[$metric])) {
            return;
        }

        $threshold = $this->thresholds[$metric];

        if ($value >= $threshold['critical']) {
            $this->handleCriticalThreshold($operationId, $metric, $value);
        } elseif ($value >= $threshold['warning']) {
            $this->handleWarningThreshold($operationId, $metric, $value);
        }
    }

    protected function handleCriticalThreshold(string $operationId, string $metric, $value): void
    {
        $context = [
            'operation_id' => $operationId,
            'metric' => $metric,
            'value' => $value,
            'threshold' => $this->thresholds[$metric]['critical']
        ];

        $this->alert->critical("Critical threshold breached for {$metric}", $context);
        Event::dispatch(new ThresholdBreached($context));

        if ($this->requiresImmediateAction($metric)) {
            $this->executeEmergencyProtocol($context);
        }
    }

    protected function handleWarningThreshold(string $operationId, string $metric, $value): void
    {
        $context = [
            'operation_id' => $operationId,
            'metric' => $metric,
            'value' => $value,
            'threshold' => $this->thresholds[$metric]['warning']
        ];

        $this->alert->warning("Warning threshold reached for {$metric}", $context);
    }

    protected function updateCriticalMetrics(string $metric, $value): void
    {
        if (!isset($this->criticalMetrics[$metric])) {
            return;
        }

        $metrics = &$this->criticalMetrics[$metric];
        $metrics['current'] = $value;
        $metrics['peak'] = max($metrics['peak'], $value);

        if ($value >= $metrics['threshold']) {
            $metrics['status'] = 'critical';
        } elseif ($value >= $metrics['threshold'] * 0.8) {
            $metrics['status'] = 'warning';
        } else {
            $metrics['status'] = 'normal';
        }
    }

    protected function validateOperation(array $monitor): void
    {
        $duration = $monitor['duration'];
        $maxDuration = $this->thresholds['operation_duration']['critical'] ?? 5.0;

        if ($duration > $maxDuration) {
            $this->handleSlowOperation($monitor);
        }

        if ($this->hasExcessiveResourceUsage($monitor)) {
            $this->handleResourceThreshold($monitor);
        }
    }

    protected function hasExcessiveResourceUsage(array $monitor): bool
    {
        foreach ($monitor['metrics'] as $metric => $data) {
            if (isset($this->thresholds[$metric]) && 
                $data['value'] >= $this->thresholds[$metric]['critical']) {
                return true;
            }
        }
        return false;
    }

    protected function handleSlowOperation(array $monitor): void
    {
        $context = [
            'operation_id' => $monitor['id'],
            'duration' => $monitor['duration'],
            'context' => $monitor['context'],
            'metrics' => $monitor['metrics']
        ];

        Event::dispatch(new PerformanceDegraded($context));
        $this->alert->warning('Slow operation detected', $context);
    }

    protected function handleResourceThreshold(array $monitor): void
    {
        $context = [
            'operation_id' => $monitor['id'],
            'metrics' => $monitor['metrics'],
            'duration' => $monitor['duration']
        ];

        Event::dispatch(new SystemAlert('resource_threshold_exceeded', $context));
        $this->alert->critical('Resource threshold exceeded', $context);
    }

    protected function requiresImmediateAction(string $metric): bool
    {
        return in_array($metric, [
            'memory_usage',
            'cpu_usage',
            'error_rate',
            'response_time'
        ]);
    }

    protected function executeEmergencyProtocol(array $context): void
    {
        // Implementation of emergency response
        Log::emergency('Executing emergency protocol', $context);
        Event::dispatch(new SystemAlert('emergency_protocol_executed', $context));
    }

    protected function persistMetrics(array $monitor): void
    {
        $this->metrics->record($monitor['id'], [
            'duration' => $monitor['duration'],
            'metrics' => $monitor['metrics'],
            'context' => $monitor['context'],
            'timestamp' => microtime(true)
        ]);
    }

    protected function getThresholdStatus(): array
    {
        $status = [];
        foreach ($this->thresholds as $metric => $config) {
            $status[$metric] = [
                'current' => $this->criticalMetrics[$metric]['current'] ?? 0,
                'threshold' => $config['critical'],
                'status' => $this->criticalMetrics[$metric]['status'] ?? 'normal'
            ];
        }
        return $status;
    }

    protected function getActiveAlerts(): array
    {
        return $this->alert->getActive();
    }

    protected function getPerformanceMetrics(): array
    {
        return $this->metrics->getLatest([
            'response_time',
            'memory_usage',
            'cpu_usage',
            'error_rate'
        ]);
    }
}
