<?php

namespace App\Core\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Core\Interfaces\AuditServiceInterface;
use App\Core\Security\Context\AuditContext;
use App\Core\Models\{AuditLog, SecurityEvent};

class AuditService implements AuditServiceInterface
{
    private array $config;
    private MetricsCollector $metrics;
    private NotificationService $notifications;

    public function __construct(
        array $config,
        MetricsCollector $metrics,
        NotificationService $notifications
    ) {
        $this->config = $config;
        $this->metrics = $metrics;
        $this->notifications = $notifications;
    }

    public function logSecureOperation(array $data): void
    {
        DB::transaction(function() use ($data) {
            $auditLog = AuditLog::create([
                'operation_type' => $data['operation'],
                'user_id' => $data['user_id'],
                'execution_time' => $data['execution_time'],
                'success' => true,
                'ip_address' => request()->ip(),
                'metadata' => $this->enrichAuditData($data),
                'timestamp' => now(),
                'hash' => $this->generateAuditHash($data)
            ]);

            $this->metrics->recordOperation($data);
            $this->checkOperationThresholds($data);
        });
    }

    public function logSecurityFailure(array $data): void
    {
        DB::transaction(function() use ($data) {
            SecurityEvent::create([
                'event_type' => 'security_failure',
                'severity' => $this->calculateSeverity($data),
                'user_id' => $data['user_id'],
                'operation' => $data['operation'],
                'error_message' => $data['exception'],
                'stack_trace' => $data['trace'],
                'ip_address' => request()->ip(),
                'metadata' => $this->enrichSecurityData($data),
                'timestamp' => now(),
                'hash' => $this->generateSecurityHash($data)
            ]);

            $this->metrics->recordFailure($data);
            $this->evaluateSecurityThreat($data);
        });
    }

    public function logAccessAttempt(array $data): void
    {
        AuditLog::create([
            'operation_type' => 'access_attempt',
            'user_id' => $data['user_id'] ?? null,
            'success' => $data['success'],
            'ip_address' => request()->ip(),
            'metadata' => [
                'auth_method' => $data['auth_method'],
                'target_resource' => $data['resource'],
                'user_agent' => request()->userAgent(),
                'geo_location' => $this->getGeoLocation(request()->ip())
            ],
            'timestamp' => now(),
            'hash' => $this->generateAccessHash($data)
        ]);

        if (!$data['success']) {
            $this->handleFailedAccess($data);
        }
    }

    public function logSystemEvent(array $data): void
    {
        DB::transaction(function() use ($data) {
            $event = SecurityEvent::create([
                'event_type' => 'system_event',
                'severity' => $data['severity'],
                'component' => $data['component'],
                'message' => $data['message'],
                'metadata' => $this->enrichSystemData($data),
                'timestamp' => now(),
                'hash' => $this->generateEventHash($data)
            ]);

            $this->metrics->recordSystemEvent($data);
            $this->evaluateSystemImpact($event);
        });
    }

    protected function calculateSeverity(array $data): string
    {
        $factors = [
            'isAuthFailure' => $data['operation'] === 'authentication',
            'isRepeatedFailure' => $this->checkRepeatedFailures($data),
            'isSensitiveOperation' => in_array($data['operation'], $this->config['sensitive_operations']),
            'hasErrorTrace' => !empty($data['trace'])
        ];

        $score = array_sum(array_map(fn($v) => $v ? 1 : 0, $factors));
        
        return match(true) {
            $score >= 3 => 'critical',
            $score >= 2 => 'high',
            $score >= 1 => 'medium',
            default => 'low'
        };
    }

    protected function enrichAuditData(array $data): array
    {
        return array_merge($data, [
            'environment' => app()->environment(),
            'session_id' => session()->getId(),
            'user_agent' => request()->userAgent(),
            'geo_location' => $this->getGeoLocation(request()->ip()),
            'server_timestamp' => microtime(true)
        ]);
    }

    protected function enrichSecurityData(array $data): array
    {
        return array_merge($data, [
            'threat_indicators' => $this->detectThreatIndicators($data),
            'previous_failures' => $this->getRecentFailures($data['user_id']),
            'system_status' => $this->getSystemStatus(),
            'security_context' => $this->getSecurityContext()
        ]);
    }

    protected function enrichSystemData(array $data): array
    {
        return array_merge($data, [
            'system_metrics' => $this->metrics->getCurrentMetrics(),
            'component_status' => $this->getComponentStatus($data['component']),
            'related_events' => $this->getRelatedEvents($data),
            'system_impact' => $this->assessSystemImpact($data)
        ]);
    }

    protected function generateAuditHash(array $data): string
    {
        return hash_hmac(
            'sha256',
            json_encode($data),
            $this->config['audit_key']
        );
    }

    protected function handleFailedAccess(array $data): void
    {
        if ($this->detectBruteForceAttempt($data)) {
            $this->triggerSecurityAlert([
                'type' => 'brute_force_attempt',
                'ip_address' => request()->ip(),
                'target_user' => $data['user_id'] ?? 'unknown',
                'attempts' => $this->getRecentFailedAttempts(request()->ip())
            ]);
        }
    }

    protected function evaluateSecurityThreat(array $data): void
    {
        $threatLevel = $this->calculateThreatLevel($data);
        
        if ($threatLevel >= $this->config['alert_threshold']) {
            $this->triggerSecurityAlert([
                'type' => 'security_threat',
                'severity' => $threatLevel,
                'data' => $data
            ]);
        }
    }

    protected function evaluateSystemImpact(SecurityEvent $event): void
    {
        if ($event->severity === 'critical') {
            $this->triggerSystemAlert($event);
        }
    }

    protected function triggerSecurityAlert(array $data): void
    {
        $this->notifications->sendSecurityAlert($data);
    }
}
