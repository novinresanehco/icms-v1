<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{DB, Log, Cache};
use App\Core\Services\{SecurityService, ValidationService, EncryptionService};
use App\Core\Models\{AuditLog, SecurityEvent, SystemMetric};
use App\Core\Exceptions\{AuditException, SecurityException};

class AuditManager
{
    private SecurityService $security;
    private ValidationService $validator;
    private EncryptionService $encryption;

    private const MAX_RETRY_ATTEMPTS = 3;
    private const LOG_RETENTION_DAYS = 90;
    private const CRITICAL_EVENTS = [
        'authentication_failure',
        'authorization_failure',
        'data_breach',
        'system_compromise'
    ];

    public function __construct(
        SecurityService $security,
        ValidationService $validator,
        EncryptionService $encryption
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->encryption = $encryption;
    }

    public function logSecurityEvent(string $type, array $data): void
    {
        DB::beginTransaction();
        try {
            $this->validateEventData($data);
            $encrypted = $this->encryptSensitiveData($data);

            $event = SecurityEvent::create([
                'type' => $type,
                'data' => $encrypted,
                'severity' => $this->determineSeverity($type),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'user_id' => auth()->id(),
                'created_at' => now()
            ]);

            if ($this->isCriticalEvent($type)) {
                $this->handleCriticalEvent($event);
            }

            $this->updateSecurityMetrics($type);
            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleLoggingFailure($type, $data, $e);
        }
    }

    public function logAuditTrail(string $action, array $data, ?string $resource = null): void
    {
        DB::beginTransaction();
        try {
            $this->validateAuditData($data);
            $encrypted = $this->encryptSensitiveData($data);

            $log = AuditLog::create([
                'action' => $action,
                'data' => $encrypted,
                'resource_type' => $resource,
                'resource_id' => $data['resource_id'] ?? null,
                'user_id' => auth()->id(),
                'ip_address' => request()->ip(),
                'created_at' => now()
            ]);

            $this->updateAuditMetrics($action);
            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleLoggingFailure($action, $data, $e);
        }
    }

    public function queryAuditLogs(array $criteria): array
    {
        try {
            $this->validateQueryCriteria($criteria);
            $this->security->validateAccess('audit.query');

            return AuditLog::where($criteria)
                ->orderBy('created_at', 'desc')
                ->limit(1000)
                ->get()
                ->map(function ($log) {
                    $log->data = $this->decryptSensitiveData($log->data);
                    return $log;
                })
                ->toArray();

        } catch (\Exception $e) {
            throw new AuditException('Audit query failed: ' . $e->getMessage());
        }
    }

    public function getSecurityMetrics(): array
    {
        try {
            $this->security->validateAccess('security.metrics');

            return Cache::remember('security.metrics', 300, function () {
                return [
                    'events_count' => $this->getEventMetrics(),
                    'severity_distribution' => $this->getSeverityDistribution(),
                    'top_event_types' => $this->getTopEventTypes(),
                    'critical_events' => $this->getCriticalEventCount()
                ];
            });

        } catch (\Exception $e) {
            throw new AuditException('Failed to retrieve security metrics: ' . $e->getMessage());
        }
    }

    protected function validateEventData(array $data): void
    {
        $rules = [
            'message' => 'required|string|max:1000',
            'context' => 'required|array',
            'severity' => 'required|in:low,medium,high,critical'
        ];

        $this->validator->validate($data, $rules);
    }

    protected function validateAuditData(array $data): void
    {
        $rules = [
            'message' => 'required|string|max:1000',
            'changes' => 'required|array',
            'resource_id' => 'sometimes|integer'
        ];

        $this->validator->validate($data, $rules);
    }

    protected function encryptSensitiveData(array $data): string
    {
        return $this->encryption->encrypt(json_encode($data));
    }

    protected function decryptSensitiveData(string $encrypted): array
    {
        return json_decode($this->encryption->decrypt($encrypted), true);
    }

    protected function determineSeverity(string $type): string
    {
        if (in_array($type, self::CRITICAL_EVENTS)) {
            return 'critical';
        }

        $severityMap = [
            'authentication' => 'high',
            'authorization' => 'high',
            'data_access' => 'medium',
            'system_change' => 'medium',
            'user_action' => 'low'
        ];

        return $severityMap[explode('_', $type)[0]] ?? 'low';
    }

    protected function isCriticalEvent(string $type): bool
    {
        return in_array($type, self::CRITICAL_EVENTS);
    }

    protected function handleCriticalEvent(SecurityEvent $event): void
    {
        Log::critical('Critical security event', [
            'event_id' => $event->id,
            'type' => $event->type,
            'data' => $event->data
        ]);

        Cache::tags(['security'])->put(
            "critical_event:{$event->id}",
            $event->toArray(),
            3600
        );

        // Trigger immediate notification
        event(new CriticalSecurityEvent($event));
    }

    protected function updateSecurityMetrics(string $type): void
    {
        $metrics = [
            "events.total",
            "events.{$type}",
            "events.severity.{$this->determineSeverity($type)}"
        ];

        foreach ($metrics as $metric) {
            SystemMetric::updateOrCreate(
                ['key' => $metric],
                ['value' => DB::raw('value + 1')]
            );
        }
    }

    protected function updateAuditMetrics(string $action): void
    {
        SystemMetric::updateOrCreate(
            ['key' => "audit.{$action}"],
            ['value' => DB::raw('value + 1')]
        );
    }

    protected function handleLoggingFailure(string $type, array $data, \Exception $e): void
    {
        Log::error('Logging failure', [
            'type' => $type,
            'data' => $data,
            'error' => $e->getMessage()
        ]);

        if ($this->isCriticalEvent($type)) {
            throw new SecurityException('Critical event logging failed');
        }

        throw new AuditException('Logging failed: ' . $e->getMessage());
    }

    protected function validateQueryCriteria(array $criteria): void
    {
        $allowedFields = [
            'action',
            'resource_type',
            'resource_id',
            'user_id',
            'created_at',
            'severity'
        ];

        foreach ($criteria as $field => $value) {
            if (!in_array($field, $allowedFields)) {
                throw new AuditException("Invalid query field: {$field}");
            }
        }
    }

    protected function cleanupOldLogs(): void
    {
        $cutoff = now()->subDays(self::LOG_RETENTION_DAYS);

        AuditLog::where('created_at', '<', $cutoff)->delete();
        SecurityEvent::where('created_at', '<', $cutoff)->delete();
    }
}
