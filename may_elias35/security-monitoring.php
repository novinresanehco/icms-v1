<?php

namespace App\Core\Security;

class SecurityMonitor implements MonitoringInterface
{
    private MetricsCollector $metrics;
    private AlertSystem $alerts;
    private AuditLogger $audit;

    private const ALERT_THRESHOLDS = [
        'failed_attempts' => 3,
        'concurrent_requests' => 100,
        'error_rate' => 0.01
    ];

    public function monitorOperation(string $operationId): void
    {
        $metrics = [
            'start_time' => microtime(true),
            'memory_start' => memory_get_usage(true),
            'cpu_start' => sys_getloadavg()[0]
        ];

        $this->metrics->record($operationId, $metrics);
    }

    public function checkSecurityMetrics(): void
    {
        $currentMetrics = $this->metrics->getCurrentMetrics();

        foreach (self::ALERT_THRESHOLDS as $metric => $threshold) {
            if ($currentMetrics[$metric] > $threshold) {
                $this->handleSecurityBreach($metric, $currentMetrics[$metric]);
            }
        }
    }

    public function recordSecurityEvent(SecurityEvent $event): void
    {
        $this->audit->logSecurityEvent($event);
        $this->metrics->incrementCounter($event->getType());

        if ($this->isHighRiskEvent($event)) {
            $this->alerts->triggerSecurityAlert($event);
        }
    }

    private function handleSecurityBreach(string $metric, $value): void
    {
        $this->alerts->triggerCriticalAlert([
            'type' => 'security_breach',
            'metric' => $metric,
            'value' => $value,
            'threshold' => self::ALERT_THRESHOLDS[$metric]
        ]);

        $this->audit->logSecurityBreach($metric, $value);
        
        if ($this->isSystemThreatening($metric, $value)) {
            $this->initiateEmergencyProtocol();
        }
    }

    private function isHighRiskEvent(SecurityEvent $event): bool
    {
        return in_array($event->getType(), [
            'unauthorized_access',
            'data_breach',
            'system_attack'
        ]);
    }

    private function isSystemThreatening(string $metric, $value): bool
    {
        return $value > (self::ALERT_THRESHOLDS[$metric] * 2);
    }

    private function initiateEmergencyProtocol(): void
    {
        // Lock down system
        app(SecurityManager::class)->lockdownSystem();

        // Notify emergency contacts
        $this->alerts->notifyEmergencyContacts([
            'title' => 'CRITICAL SECURITY BREACH',
            'metrics' => $this->metrics->getCurrentMetrics(),
            'timestamp' => now()
        ]);

        // Log emergency event
        $this->audit->logEmergency('System lockdown initiated due to critical security breach');
    }
}
