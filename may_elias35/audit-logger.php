<?php
namespace App\Core\Security;

use Illuminate\Support\Facades\{DB, Log, Cache};
use App\Core\Interfaces\AuditInterface;
use App\Core\Models\{AuditLog, SecurityEvent};
use App\Core\Services\MetricsCollector;

class AuditLogger implements AuditInterface 
{
    private MetricsCollector $metrics;
    private AlertSystem $alerts;
    private LogProcessor $processor;

    private const CRITICAL_EVENTS = [
        'authentication_failure',
        'unauthorized_access',
        'data_breach',
        'system_compromise'
    ];

    public function logSecurityEvent(SecurityEvent $event, SecurityContext $context): void
    {
        DB::transaction(function() use ($event, $context) {
            $log = AuditLog::create([
                'event_type' => $event->type,
                'severity' => $event->severity,
                'user_id' => $context->getUserId(),
                'ip_address' => $context->getIpAddress(),
                'resource' => $context->getResource(),
                'operation' => $context->getOperation(),
                'result' => $event->result,
                'metadata' => $this->processor->processMetadata($event->metadata),
                'timestamp' => microtime(true)
            ]);

            if ($this->isCriticalEvent($event)) {
                $this->handleCriticalEvent($log, $event, $context);
            }

            $this->updateMetrics($event);
            $this->storeAuditTrail($log);
        });
    }

    public function logAuthFailure(SecurityContext $context): void
    {
        $event = new SecurityEvent([
            'type' => 'authentication_failure',
            'severity' => 'high',
            'metadata' => [
                'attempt_count' => $this->getFailedAttempts($context),
                'authentication_method' => $context->getAuthMethod()
            ]
        ]);

        $this->logSecurityEvent($event, $context);
    }

    public function logUnauthorizedAccess(SecurityContext $context): void
    {
        $event = new SecurityEvent([
            'type' => 'unauthorized_access',
            'severity' => 'critical',
            'metadata' => [
                'required_permissions' => $context->getRequiredPermissions(),
                'user_permissions' => $context->getUserPermissions()
            ]
        ]);

        $this->logSecurityEvent($event, $context);
    }

    public function logSystemFailure(\Throwable $e, SecurityContext $context): void
    {
        $event = new SecurityEvent([
            'type' => 'system_failure',
            'severity' => 'critical',
            'metadata' => [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'system_state' => $this->captureSystemState()
            ]
        ]);

        $this->logSecurityEvent($event, $context);
        $this->alerts->notifySecurityTeam($event);
    }

    private function handleCriticalEvent(AuditLog $log, SecurityEvent $event, SecurityContext $context): void
    {
        $this->alerts->triggerSecurityAlert($event);
        $this->storeForensicData($log, $context);
        
        if ($event->requiresImmediateAction()) {
            $this->initiateEmergencyProtocol($event);
        }
    }

    private function storeAuditTrail(AuditLog $log): void
    {
        Cache::tags('audit_trail')->put(
            'audit_' . $log->id,
            $log->toArray(),
            now()->addYear()
        );

        if ($log->requiresPermanentStorage()) {
            $this->archiveAuditLog($log);
        }
    }

    private function updateMetrics(SecurityEvent $event): void
    {
        $this->metrics->incrementCounter(
            'security_events',
            ['type' => $event->type, 'severity' => $event->severity]
        );
    }

    private function isCriticalEvent(SecurityEvent $event): bool
    {
        return in_array($event->type, self::CRITICAL_EVENTS) ||
               $event->severity === 'critical';
    }

    private function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'cpu_load' => sys_getloadavg(),
            'active_connections' => $this->getActiveConnections(),
            'error_logs' => $this->getRecentErrors()
        ];
    }

    private function storeForensicData(AuditLog $log, SecurityContext $context): void
    {
        DB::table('forensic_data')->insert([
            'audit_log_id' => $log->id,
            'raw_request' => $context->getRawRequest(),
            'system_state' => json_encode($this->captureSystemState()),
            'environment' => $this->captureEnvironment(),
            'timestamp' => now()
        ]);
    }
}
