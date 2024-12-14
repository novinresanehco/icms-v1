<?php

namespace App\Core\Monitoring;

use App\Core\Interfaces\{
    MonitoringServiceInterface,
    MetricsCollectorInterface,
    AlertManagerInterface
};

class MonitoringService implements MonitoringServiceInterface
{
    private MetricsCollectorInterface $metrics;
    private AlertManagerInterface $alerts;
    private array $thresholds;
    private array $activeMonitors = [];

    public function __construct(
        MetricsCollectorInterface $metrics,
        AlertManagerInterface $alerts,
        array $thresholds
    ) {
        $this->metrics = $metrics;
        $this->alerts = $alerts;
        $this->thresholds = $thresholds;
    }

    public function startMonitoring(string $id, array $context): void
    {
        $this->activeMonitors[$id] = [
            'start_time' => microtime(true),
            'context' => $context,
            'metrics' => []
        ];

        $this->metrics->recordEvent('monitor_start', [
            'monitor_id' => $id,
            'context' => $context
        ]);
    }

    public function recordMetric(string $monitorId, string $metric, $value): void
    {
        if (!isset($this->activeMonitors[$monitorId])) {
            throw new MonitoringException("No active monitor found: {$monitorId}");
        }

        $this->activeMonitors[$monitorId]['metrics'][$metric] = $value;

        $this->metrics->recordMetric($metric, $value, [
            'monitor_id' => $monitorId
        ]);

        $this->checkThresholds($monitorId, $metric, $value);
    }

    public function stopMonitoring(string $id): array
    {
        if (!isset($this->activeMonitors[$id])) {
            throw new MonitoringException("No active monitor found: {$id}");
        }

        $duration = microtime(true) - $this->activeMonitors[$id]['start_time'];
        $metrics = $this->activeMonitors[$id]['metrics'];

        $this->metrics->recordEvent('monitor_stop', [
            'monitor_id' => $id,
            'duration' => $duration,
            'metrics' => $metrics
        ]);

        unset($this->activeMonitors[$id]);

        return [
            'duration' => $duration,
            'metrics' => $metrics
        ];
    }

    public function checkHealth(): HealthStatus
    {
        $metrics = $this->metrics->getRecentMetrics();
        $alerts = $this->alerts->getActiveAlerts();

        $status = $this->calculateSystemHealth($metrics, $alerts);

        $this->metrics->recordEvent('health_check', [
            'status' => $status->getStatus(),
            'metrics' => $metrics,
            'alerts' => $alerts
        ]);

        return $status;
    }

    protected function checkThresholds(string $monitorId, string $metric, $value): void
    {
        if (!isset($this->thresholds[$metric])) {
            return;
        }

        $threshold = $this->thresholds[$metric];

        if ($this->isThresholdViolated($value, $threshold)) {
            $this->handleThresholdViolation($monitorId, $metric, $value, $threshold);
        }
    }

    protected function isThresholdViolated($value, array $threshold): bool
    {
        return match ($threshold['operator']) {
            '>' => $value > $threshold['value'],
            '<' => $value < $threshold['value'],
            '>=' => $value >= $threshold['value'],
            '<=' => $value <= $threshold['value'],
            '=' => $value == $threshold['value'],
            default => false
        };
    }

    protected function handleThresholdViolation(
        string $monitorId,
        string $metric,
        $value,
        array $threshold
    ): void {
        $alert = [
            'type' => 'threshold_violation',
            'monitor_id' => $monitorId,
            'metric' => $metric,
            'value' => $value,
            'threshold' => $threshold,
            'severity' => $threshold['severity'] ?? 'WARNING'
        ];

        $this->alerts->triggerAlert($alert);

        $this->metrics->recordEvent('threshold_violation', $alert);
    }

    protected function calculateSystemHealth(array $metrics, array $alerts): HealthStatus
    {
        $criticalAlerts = array_filter($alerts, fn($alert) => 
            $alert['severity'] === 'CRITICAL'
        );

        if (!empty($criticalAlerts)) {
            return new HealthStatus('CRITICAL', $criticalAlerts);
        }

        $warningAlerts = array_filter($alerts, fn($alert) =>
            $alert['severity'] === 'WARNING'
        );

        if (!empty($warningAlerts)) {
            return new HealthStatus('WARNING', $warningAlerts);
        }

        return new HealthStatus('HEALTHY');
    }
}
