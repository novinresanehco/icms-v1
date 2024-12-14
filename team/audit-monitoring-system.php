<?php

namespace App\Core\Audit;

use App\Core\Security\SecurityManager;
use App\Core\Protection\CoreProtectionSystem;
use App\Core\Exceptions\{AuditException, MonitoringException};
use Illuminate\Support\Facades\Log;

class AuditMonitoringManager implements AuditInterface, MonitoringInterface
{
    private SecurityManager $security;
    private CoreProtectionSystem $protection;
    private StorageManager $storage;
    private AlertSystem $alerts;
    private MetricsCollector $metrics;

    public function recordAuditEvent(AuditEvent $event, SecurityContext $context): void
    {
        $this->protection->executeProtectedOperation(
            function() use ($event, $context) {
                $validatedEvent = $this->validateAuditEvent($event);
                $enrichedEvent = $this->enrichEventData($validatedEvent, $context);
                
                $this->storeAuditEvent($enrichedEvent);
                $this->processAuditTriggers($enrichedEvent);
                $this->metrics->recordAuditEvent($enrichedEvent);
            },
            $context
        );
    }

    public function monitorMetric(string $metric, $value, SecurityContext $context): void
    {
        $this->protection->executeProtectedOperation(
            function() use ($metric, $value, $context) {
                $validatedMetric = $this->validateMetric($metric, $value);
                $this->storeMetric($validatedMetric);
                $this->checkThresholds($validatedMetric);
            },
            $context
        );
    }

    public function trackSecurityEvent(SecurityEvent $event, SecurityContext $context): void
    {
        $this->protection->executeProtectedOperation(
            function() use ($event, $context) {
                $validatedEvent = $this->validateSecurityEvent($event);
                $this->processSecurityEvent($validatedEvent);
                $this->triggerSecurityAlerts($validatedEvent);
            },
            $context
        );
    }

    public function generateAuditReport(ReportCriteria $criteria, SecurityContext $context): AuditReport
    {
        return $this->protection->executeProtectedOperation(
            function() use ($criteria, $context) {
                $validatedCriteria = $this->validateReportCriteria($criteria);
                $events = $this->queryAuditEvents($validatedCriteria);
                return $this->compileAuditReport($events, $validatedCriteria);
            },
            $context
        );
    }

    private function validateAuditEvent(AuditEvent $event): AuditEvent
    {
        if (!$event->validate()) {
            throw new AuditException('Invalid audit event');
        }

        if ($event->isSensitive() && !$event->isEncrypted()) {
            throw new SecurityException('Sensitive event must be encrypted');
        }

        return $event;
    }

    private function enrichEventData(AuditEvent $event, SecurityContext $context): AuditEvent
    {
        return $event->withAdditionalData([
            'timestamp' => now(),
            'user_id' => $context->getUserId(),
            'ip_address' => $context->getIpAddress(),
            'session_id' => $context->getSessionId(),
            'trace_id' => $this->generateTraceId()
        ]);
    }

    private function storeAuditEvent(AuditEvent $event): void
    {
        $this->storage->storeSecurely(
            'audit_events',
            $event->toArray(),
            [
                'encryption' => true,
                'compression' => true,
                'retention' => config('audit.retention_period')
            ]
        );
    }

    private function processAuditTriggers(AuditEvent $event): void
    {
        $triggers = config('audit.triggers');
        
        foreach ($triggers as $trigger) {
            if ($trigger->matches($event)) {
                $this->executeTriggerAction($trigger, $event);
            }
        }
    }

    private function validateMetric(string $metric, $value): ValidatedMetric
    {
        if (!$this->isValidMetricName($metric)) {
            throw new MonitoringException('Invalid metric name');
        }

        if (!$this->isValidMetricValue($value)) {
            throw new MonitoringException('Invalid metric value');
        }

        return new ValidatedMetric($metric, $value);
    }

    private function checkThresholds(ValidatedMetric $metric): void
    {
        $thresholds = config("monitoring.thresholds.{$metric->getName()}");
        
        foreach ($thresholds as $threshold) {
            if ($threshold->isExceeded($metric->getValue())) {
                $this->handleThresholdViolation($threshold, $metric);
            }
        }
    }

    private function validateSecurityEvent(SecurityEvent $event): SecurityEvent
    {
        if (!$event->validate()) {
            throw new SecurityException('Invalid security event');
        }

        $this->validateEventSeverity($event);
        $this->validateEventContext($event);

        return $event;
    }

    private function processSecurityEvent(SecurityEvent $event): void
    {
        if ($event->isCritical()) {
            $this->handleCriticalSecurityEvent($event);
        }

        $this->correlateSecurityEvent($event);
        $this->updateSecurityMetrics($event);
    }

    private function triggerSecurityAlerts(SecurityEvent $event): void
    {
        $alertRules = config('security.alert_rules');
        
        foreach ($alertRules as $rule) {
            if ($rule->appliesTo($event)) {
                $this->alerts->trigger(
                    $rule->generateAlert($event)
                );
            }
        }
    }

    private function handleCriticalSecurityEvent(SecurityEvent $event): void
    {
        Log::critical('Critical security event detected', [
            'event' => $event->toArray(),
            'timestamp' => now(),
            'severity' => $event->getSeverity()
        ]);

        $this->alerts->triggerCritical(
            new CriticalSecurityAlert($event)
        );

        if ($event->requiresImmediateAction()) {
            $this->executeEmergencyProtocols($event);
        }
    }
}
