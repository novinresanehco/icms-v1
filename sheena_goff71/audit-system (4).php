<?php

namespace App\Core\Audit;

use Illuminate\Support\Facades\{DB, Log, Event};
use App\Core\Interfaces\{
    AuditInterface,
    SecurityInterface,
    ValidationInterface
};
use App\Core\Events\{AuditEvent, SecurityEvent, SystemEvent};
use App\Core\Models\{AuditLog, SecurityLog, SystemLog};
use App\Core\Exceptions\{AuditException, ValidationException};

class AuditManager implements AuditInterface
{
    protected SecurityInterface $security;
    protected ValidationInterface $validator;
    protected array $config;
    protected array $criticalEvents = [];

    public function __construct(
        SecurityInterface $security,
        ValidationInterface $validator,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->config = $config;
    }

    public function logCriticalOperation(string $operation, array $data, array $context = []): void
    {
        DB::beginTransaction();

        try {
            // Validate operation data
            $this->validateAuditData($operation, $data);

            // Create secure context
            $secureContext = $this->createSecureContext($context);

            // Generate audit trail
            $auditTrail = $this->generateAuditTrail($operation, $data, $secureContext);

            // Store audit log
            $log = new AuditLog([
                'operation' => $operation,
                'data' => $this->security->encryptSensitiveData($data),
                'context' => $secureContext,
                'audit_trail' => $auditTrail,
                'timestamp' => now(),
                'hash' => $this->generateSecureHash($data, $auditTrail)
            ]);

            $log->save();

            // Track critical events
            $this->trackCriticalEvent($operation, $log);

            // Dispatch audit event
            Event::dispatch(new AuditEvent($operation, $log));

            DB::commit();

        } catch (Exception $e) {
            DB::rollBack();
            $this->handleAuditFailure($e, $operation, $data);
            throw $e;
        }
    }

    public function logSecurityEvent(string $event, array $data, array $context = []): void
    {
        try {
            $this->validateSecurityEvent($event, $data);

            $log = new SecurityLog([
                'event' => $event,
                'data' => $this->security->encryptSensitiveData($data),
                'context' => $this->createSecureContext($context),
                'severity' => $this->determineSeverity($event, $data),
                'timestamp' => now()
            ]);

            $log->save();

            if ($this->isHighSeverityEvent($event, $log)) {
                $this->handleHighSeverityEvent($log);
            }

            Event::dispatch(new SecurityEvent($event, $log));

        } catch (Exception $e) {
            $this->handleSecurityLogFailure($e, $event, $data);
            throw $e;
        }
    }

    public function logSystemEvent(string $event, array $data, array $context = []): void
    {
        try {
            $this->validateSystemEvent($event, $data);

            $log = new SystemLog([
                'event' => $event,
                'data' => $data,
                'context' => $context,
                'timestamp' => now()
            ]);

            $log->save();

            if ($this->isSystemCritical($event)) {
                $this->handleCriticalSystemEvent($log);
            }

            Event::dispatch(new SystemEvent($event, $log));

        } catch (Exception $e) {
            $this->handleSystemLogFailure($e, $event, $data);
            throw $e;
        }
    }

    public function getAuditTrail(string $operation, array $filters = []): Collection
    {
        return AuditLog::where('operation', $operation)
            ->when($filters, function($query) use ($filters) {
                foreach ($filters as $field => $value) {
                    $query->where($field, $value);
                }
            })
            ->orderBy('timestamp', 'desc')
            ->get();
    }

    public function validateAuditIntegrity(AuditLog $log): bool
    {
        $hash = $this->generateSecureHash($log->data, $log->audit_trail);
        return hash_equals($hash, $log->hash);
    }

    protected function validateAuditData(string $operation, array $data): void
    {
        $rules = $this->config['validation_rules'][$operation] ?? [];
        
        if (!$this->validator->validate($data, $rules)) {
            throw new ValidationException("Invalid audit data for operation: {$operation}");
        }
    }

    protected function createSecureContext(array $context): array
    {
        return array_merge($context, [
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'timestamp' => now()->toIso8601String(),
            'session_id' => session()->getId()
        ]);
    }

    protected function generateAuditTrail(string $operation, array $data, array $context): array
    {
        return [
            'operation_id' => uniqid('op_'),
            'operation' => $operation,
            'data_snapshot' => $data,
            'context' => $context,
            'timestamp' => now()->toIso8601String(),
            'system_state' => $this->captureSystemState()
        ];
    }

    protected function generateSecureHash(array $data, array $auditTrail): string
    {
        $payload = json_encode([
            'data' => $data,
            'audit_trail' => $auditTrail,
            'timestamp' => now()->toIso8601String()
        ]);

        return hash_hmac('sha256', $payload, config('app.key'));
    }

    protected function trackCriticalEvent(string $operation, AuditLog $log): void
    {
        if ($this->isCriticalOperation($operation)) {
            $this->criticalEvents[] = [
                'operation' => $operation,
                'log_id' => $log->id,
                'timestamp' => now()
            ];
        }
    }

    protected function handleAuditFailure(Exception $e, string $operation, array $data): void
    {
        Log::error('Audit logging failed', [
            'operation' => $operation,
            'data' => $data,
            'exception' => [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]
        ]);

        Event::dispatch(new AuditEvent('audit_failure', [
            'operation' => $operation,
            'error' => $e->getMessage()
        ]));
    }

    protected function validateSecurityEvent(string $event, array $data): void
    {
        if (!isset($this->config['security_events'][$event])) {
            throw new ValidationException("Invalid security event: {$event}");
        }

        $rules = $this->config['security_events'][$event]['validation'] ?? [];
        if (!$this->validator->validate($data, $rules)) {
            throw new ValidationException("Invalid security event data for: {$event}");
        }
    }

    protected function determineSeverity(string $event, array $data): string
    {
        return $this->config['security_events'][$event]['severity'] ?? 'info';
    }

    protected function isHighSeverityEvent(string $event, SecurityLog $log): bool
    {
        return in_array($log->severity, ['critical', 'high']);
    }

    protected function handleHighSeverityEvent(SecurityLog $log): void
    {
        // Implementation of high severity event handling
        Log::critical('High severity security event detected', $log->toArray());
    }

    protected function validateSystemEvent(string $event, array $data): void
    {
        $rules = $this->config['system_events'][$event] ?? [];
        if (!$this->validator->validate($data, $rules)) {
            throw new ValidationException("Invalid system event data for: {$event}");
        }
    }

    protected function isSystemCritical(string $event): bool
    {
        return in_array($event, $this->config['critical_system_events'] ?? []);
    }

    protected function handleCriticalSystemEvent(SystemLog $log): void
    {
        // Implementation of critical system event handling
        Log::critical('Critical system event detected', $log->toArray());
    }

    protected function isCriticalOperation(string $operation): bool
    {
        return in_array($operation, $this->config['critical_operations'] ?? []);
    }

    protected function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'cpu_load' => sys_getloadavg(),
            'active_sessions' => count(session()->all()),
            'database_connections' => DB::connection()->getDatabaseName()
        ];
    }
}
