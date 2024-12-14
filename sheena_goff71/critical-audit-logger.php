<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Core\Interfaces\AuditInterface;
use App\Core\Security\Events\SecurityEvent;

class CriticalAuditLogger implements AuditInterface
{
    private MetricsCollector $metrics;
    private SecurityConfig $config;
    private AlertSystem $alerts;
    
    private const CRITICAL_EVENTS = [
        'authentication_failure',
        'authorization_failure',
        'data_breach_attempt',
        'system_compromise_attempt',
        'rate_limit_exceeded'
    ];

    public function logSecurityEvent(SecurityEvent $event): void
    {
        DB::transaction(function() use ($event) {
            $this->persistEvent($event);
            $this->analyzeEvent($event);
            $this->updateMetrics($event);
            
            if ($this->isCriticalEvent($event)) {
                $this->handleCriticalEvent($event);
            }
        });
    }

    protected function persistEvent(SecurityEvent $event): void
    {
        DB::table('security_audit_log')->insert([
            'event_type' => $event->getType(),
            'severity' => $event->getSeverity(),
            'context' => json_encode($event->getContext()),
            'user_id' => $event->getUserId(),
            'ip_address' => $event->getIpAddress(),
            'resource' => $event->getResource(),
            'result' => $event->getResult(),
            'timestamp' => now(),
            'hash' => $this->generateEventHash($event),
            'metadata' => json_encode($event->getMetadata())
        ]);
    }

    protected function analyzeEvent(SecurityEvent $event): void
    {
        $patterns = $this->config->getSecurityPatterns();
        
        foreach ($patterns as $pattern) {
            if ($pattern->matches($event)) {
                $this->handlePatternMatch($pattern, $event);
            }
        }
        
        if ($this->detectAnomalies($event)) {
            $this->handleAnomalyDetection($event);
        }
    }

    protected function updateMetrics(SecurityEvent $event): void
    {
        $this->metrics->incrementCounter(
            "security_events.{$event->getType()}",
            1,
            ['severity' => $event->getSeverity()]
        );

        $this->metrics->recordValue(
            "security_events.response_time",
            $event->getResponseTime(),
            ['event_type' => $event->getType()]
        );

        if ($event->isFailure()) {
            $this->metrics->incrementCounter(
                "security_events.failures",
                1,
                ['type' => $event->getType()]
            );
        }
    }

    protected function handleCriticalEvent(SecurityEvent $event): void
    {
        $this->alerts->triggerCriticalAlert($event);
        
        $this->persistCriticalEventData($event);
        
        if ($this->requiresImmediateAction($event)) {
            $this->executeEmergencyProtocol($event);
        }

        $this->notifySecurityTeam($event);
    }

    protected function generateEventHash(SecurityEvent $event): string
    {
        $data = json_encode([
            'type' => $event->getType(),
            'context' => $event->getContext(),
            'timestamp' => $event->getTimestamp(),
            'user_id' => $event->getUserId(),
            'ip_address' => $event->getIpAddress()
        ]);

        return hash_hmac('sha256', $data, $this->config->getHashKey());
    }

    protected function detectAnomalies(SecurityEvent $event): bool
    {
        $history = $this->getRecentEvents($event->getUserId());
        return $this->anomalyDetector->analyze($event, $history);
    }

    protected function handlePatternMatch(SecurityPattern $pattern, SecurityEvent $event): void
    {
        $this->alerts->handlePatternMatch($pattern, $event);
        
        if ($pattern->isCritical()) {
            $this->executeCriticalResponse($pattern, $event);
        }

        $this->logPatternMatch($pattern, $event);
    }

    protected function handleAnomalyDetection(SecurityEvent $event): void
    {
        $this->alerts->reportAnomaly($event);
        $this->incrementAnomalyCounter($event);
        $this->updateThreatScore($event);
    }

    protected function persistCriticalEventData(SecurityEvent $event): void
    {
        DB::table('critical_security_events')->insert([
            'event_id' => $event->getId(),
            'full_context' => json_encode($event->getFullContext()),
            'system_state' => json_encode($this->captureSystemState()),
            'severity_analysis' => json_encode($this->analyzeSeverity($event)),
            'timestamp' => now(),
            'response_data' => json_encode($this->prepareResponseData($event))
        ]);
    }

    protected function executeEmergencyProtocol(SecurityEvent $event): void
    {
        $protocol = $this->config->getEmergencyProtocol($event->getType());
        $protocol->execute($event);
        
        $this->logProtocolExecution($protocol, $event);
        $this->verifyProtocolEffectiveness($protocol, $event);
    }

    protected function getRecentEvents(string $userId): array
    {
        return DB::table('security_audit_log')
            ->where('user_id', $userId)
            ->where('timestamp', '>=', now()->subHours(24))
            ->orderBy('timestamp', 'desc')
            ->limit(100)
            ->get()
            ->toArray();
    }

    protected function isCriticalEvent(SecurityEvent $event): bool
    {
        return in_array($event->getType(), self::CRITICAL_EVENTS) ||
               $event->getSeverity() >= $this->config->getCriticalSeverityThreshold();
    }

    protected function requiresImmediateAction(SecurityEvent $event): bool
    {
        return $event->getSeverity() >= $this->config->getImmediateActionThreshold() ||
               $this->detectsActiveThreat($event);
    }

    protected function detectsActiveThreat(SecurityEvent $event): bool
    {
        return $this->threatDetector->analyze($event)->isActive();
    }
}
