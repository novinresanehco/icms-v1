<?php

namespace App\Core\Monitoring;

use App\Core\Security\SecurityContext;
use App\Core\Metrics\MetricsCollector;
use App\Core\Alert\AlertManager;

class SystemMonitor implements MonitoringInterface
{
    private MetricsCollector $metrics;
    private AlertManager $alerts;
    private array $thresholds;
    private array $activeMonitoring = [];

    public function __construct(
        MetricsCollector $metrics,
        AlertManager $alerts,
        array $thresholds
    ) {
        $this->metrics = $metrics;
        $this->alerts = $alerts;
        $this->thresholds = $thresholds;
    }

    public function startOperation(string $operation): string
    {
        $monitoringId = $this->generateMonitoringId();
        
        $this->activeMonitoring[$monitoringId] = [
            'operation' => $operation,
            'start_time' => microtime(true),
            'metrics' => [],
            'state' => 'active'
        ];

        return $monitoringId;
    }

    public function recordMetric(string $monitoringId, string $metric, $value): void
    {
        if (!isset($this->activeMonitoring[$monitoringId])) {
            throw new MonitoringException('Invalid monitoring ID');
        }

        $this->activeMonitoring[$monitoringId]['metrics'][$metric] = [
            'value' => $value,
            'timestamp' => microtime(true)
        ];

        $this->checkThreshold($metric, $value);
    }

    public function endOperation(string $monitoringId): void
    {
        if (!isset($this->activeMonitoring[$monitoringId])) {
            throw new MonitoringException('Invalid monitoring ID');
        }

        $monitoring = $this->activeMonitoring[$monitoringId];
        $duration = microtime(true) - $monitoring['start_time'];

        $this->metrics->record($monitoring['operation'], [
            'duration' => $duration,
            'metrics' => $monitoring['metrics']
        ]);

        unset($this->activeMonitoring[$monitoringId]);
    }

    public function enableEnhancedMonitoring(): void
    {
        $this->metrics->setEnhancedMode(true);
        $this->alerts->setHighSensitivity(true);
    }

    private function checkThreshold(string $metric, $value): void
    {
        if (isset($this->thresholds[$metric])) {
            $threshold = $this->thresholds[$metric];

            if ($value > $threshold['critical']) {
                $this->alerts->triggerCriticalAlert($metric, $value);
            } elseif ($value > $threshold['warning']) {
                $this->alerts->triggerWarningAlert($metric, $value);
            }
        }
    }

    private function generateMonitoringId(): string
    {
        return uniqid('mon_', true);
    }
}

interface MonitoringInterface
{
    public function startOperation(string $operation): string;
    public function recordMetric(string $monitoringId, string $metric, $value): void;
    public function endOperation(string $monitoringId): void;
    public function enableEnhancedMonitoring(): void;
}
