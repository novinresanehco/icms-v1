<?php

namespace App\Core\Audit;

class AuditManager implements AuditInterface
{
    private LogStore $store;
    private SecurityManager $security;
    private EncryptionService $encryption;
    private IntegrityChecker $integrity;
    private AlertSystem $alerts;
    private MetricsCollector $metrics;

    public function __construct(
        LogStore $store,
        SecurityManager $security,
        EncryptionService $encryption,
        IntegrityChecker $integrity,
        AlertSystem $alerts,
        MetricsCollector $metrics
    ) {
        $this->store = $store;
        $this->security = $security;
        $this->encryption = $encryption;
        $this->integrity = $integrity;
        $this->alerts = $alerts;
        $this->metrics = $metrics;
    }

    public function logCriticalEvent(string $event, array $data): void
    {
        $logId = uniqid('log_', true);
        
        try {
            $this->validateEventData($data);
            $this->security->validateContext();
            
            $enrichedData = $this->enrichEventData($event, $data);
            $secureData = $this->secureEventData($enrichedData);
            
            $this->storeAuditLog($logId, $event, $secureData);
            $this->processAuditEvent($event, $secureData);
            
        } catch (\Exception $e) {
            $this->handleLogFailure($logId, $event, $e);
            throw new AuditException('Audit logging failed', 0, $e);
        }
    }

    private function validateEventData(array $data): void
    {
        if (!isset($data['timestamp'])) {
            $data['timestamp'] = now();
        }

        if (!isset($data['source'])) {
            throw new ValidationException('Event source required');
        }

        if (!isset($data['actor'])) {
            throw new ValidationException('Event actor required');
        }
    }

    private function enrichEventData(string $event, array $data): array
    {
        return array_merge($data, [
            'event_id' => uniqid('evt_', true),
            'environment' => config('app.env'),
            'system_state' => $this->getSystemState(),
            'security_context' => $this->security->getContext(),
            'request_context' => $this->getRequestContext(),
            'metadata' => [
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'session_id' => session()->getId()
            ]
        ]);
    }

    private function secureEventData(array $data): array
    {
        $sensitiveFields = $this->security->getSensitiveFields();
        
        foreach ($data as $key => $value) {
            if (in_array($key, $sensitiveFields)) {
                $data[$key] = $this->encryption->encrypt($value);
            }
        }

        $data['_integrity'] = $this->integrity->generateHash($data);
        return $data;
    }

    private function storeAuditLog(string $logId, string $event, array $data): void
    {
        $encryptedLog = $this->encryption->encrypt(json_encode([
            'log_id' => $logId,
            'event' => $event,
            'data' => $data
        ]));

        $stored = $this->store->store($logId, $encryptedLog);
        
        if (!$stored) {
            throw new StorageException('Failed to store audit log');
        }

        $this->integrity->recordLogHash($logId, $data['_integrity']);
    }

    private function processAuditEvent(string $event, array $data): void
    {
        if ($this->isSecurityEvent($event)) {
            $this->processSecurity($event, $data);
        }

        if ($this->isComplianceEvent($event)) {
            $this->processCompliance($event, $data);
        }

        if ($this->isAlertableEvent($event)) {
            $this->processAlert($event, $data);
        }

        $this->metrics->recordAuditEvent($event, $data);
    }

    private function processSecurity(string $event, array $data): void
    {
        $securityEvent = new SecurityEvent($event, $data);
        
        if ($securityEvent->isCritical()) {
            $this->security->handleCriticalEvent($securityEvent);
            $this->alerts->triggerSecurityAlert($securityEvent);
        }

        $this->security->trackSecurityEvent($securityEvent);
    }

    private function processCompliance(string $event, array $data): void
    {
        $complianceEvent = new ComplianceEvent($event, $data);
        
        if ($complianceEvent->isViolation()) {
            $this->alerts->triggerComplianceAlert($complianceEvent);
        }

        $this->security->trackComplianceEvent($complianceEvent);
    }

    private function processAlert(string $event, array $data): void
    {
        $alertEvent = new AlertEvent($event, $data);
        $this->alerts->processAlertEvent($alertEvent);
    }

    private function handleLogFailure(string $logId, string $event, \Exception $e): void
    {
        try {
            $failureLog = [
                'log_id' => $logId,
                'event' => $event,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'timestamp' => now()
            ];

            $this->store->storeFailure($logId, $failureLog);
            $this->alerts->triggerAuditFailure($logId, $failureLog);

            if ($e instanceof SecurityException) {
                $this->security->handleSecurityIncident($logId, $e);
            }

        } catch (\Exception $fallbackError) {
            // Critical failure in audit system
            $this->alerts->triggerCriticalAuditFailure($fallbackError);
            $this->security->initiateEmergencyProtocol();
        }
    }

    private function getSystemState(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'cpu_load' => sys_getloadavg(),
            'disk_usage' => disk_free_space('/'),
            'uptime' => time() - LARAVEL_START
        ];
    }

    private function getRequestContext(): array
    {
        return [
            'url' => request()->fullUrl(),
            'method' => request()->method(),
            'route' => request()->route() ? request()->route()->getName() : null,
            'parameters' => $this->filterSensitiveData(request()->all())
        ];
    }

    private function filterSensitiveData(array $data): array
    {
        $sensitiveFields = $this->security->getSensitiveFields();
        
        foreach ($data as $key => $value) {
            if (in_array($key, $sensitiveFields)) {
                $data[$key] = '[REDACTED]';
            }
        }

        return $data;
    }

    private function isSecurityEvent(string $event): bool
    {
        return str_starts_with($event, 'security.');
    }

    private function isComplianceEvent(string $event): bool
    {
        return str_starts_with($event, 'compliance.');
    }

    private function isAlertableEvent(string $event): bool
    {
        return in_array($event, config('audit.alertable_events'));
    }
}
