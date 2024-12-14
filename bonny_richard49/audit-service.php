<?php

namespace App\Core\Protection;

use App\Core\Contracts\AuditInterface;
use Illuminate\Support\Facades\{DB, Log};
use App\Models\AuditLog;
use Carbon\Carbon;

class AuditService implements AuditInterface
{
    private string $systemId;
    private SecurityConfig $config;
    private AlertService $alerts;

    public function __construct(
        string $systemId,
        SecurityConfig $config,
        AlertService $alerts
    ) {
        $this->systemId = $systemId;
        $this->config = $config;
        $this->alerts = $alerts;
    }

    public function logValidation(array $context): void
    {
        DB::transaction(function() use ($context) {
            $this->createAuditEntry('validation', [
                'context' => $context,
                'timestamp' => Carbon::now(),
                'system_id' => $this->systemId,
                'status' => 'success'
            ]);
        });
    }

    public function logValidationFailure(\Exception $e, array $context): void
    {
        DB::transaction(function() use ($e, $context) {
            $entry = $this->createAuditEntry('validation_failure', [
                'context' => $context,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'trace' => $e->getTraceAsString()
                ],
                'timestamp' => Carbon::now(),
                'system_id' => $this->systemId,
                'status' => 'failure'
            ]);

            if ($this->isSecurityCritical($e)) {
                $this->alerts->triggerSecurityAlert([
                    'audit_id' => $entry->id,
                    'severity' => 'critical',
                    'type' => 'validation_failure'
                ]);
            }
        });
    }

    public function logOperationFailure(
        \Exception $e, 
        array $context, 
        string $monitoringId
    ): void {
        DB::transaction(function() use ($e, $context, $monitoringId) {
            $entry = $this->createAuditEntry('operation_failure', [
                'context' => $context,
                'monitoring_id' => $monitoringId,
                'error' => [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'trace' => $e->getTraceAsString()
                ],
                'system_state' => $this->captureSystemState(),
                'timestamp' => Carbon::now(),
                'system_id' => $this->systemId,
                'status' => 'failure'
            ]);

            $this->handleFailureEscalation($entry, $e);
        });
    }

    public function logSecurityEvent(string $type, array $data): void
    {
        DB::transaction(function() use ($type, $data) {
            $entry = $this->createAuditEntry('security_event', [
                'type' => $type,
                'data' => $data,
                'timestamp' => Carbon::now(),
                'system_id' => $this->systemId,
                'status' => 'recorded'
            ]);

            if ($this->isHighSeverityEvent($type, $data)) {
                $this->alerts->triggerSecurityAlert([
                    'audit_id' => $entry->id,
                    'severity' => 'high',
                    'type' => 'security_event'
                ]);
            }
        });
    }

    public function logAccessAttempt(array $context, bool $success): void
    {
        DB::transaction(function() use ($context, $success) {
            $entry = $this->createAuditEntry('access_attempt', [
                'context' => $context,
                'success' => $success,
                'timestamp' => Carbon::now(),
                'system_id' => $this->systemId,
                'status' => $success ? 'success' : 'failure'
            ]);

            if (!$success && $this->isFailedAccessSuspicious($context)) {
                $this->alerts->triggerSecurityAlert([
                    'audit_id' => $entry->id,
                    'severity' => 'high',
                    'type' => 'suspicious_access'
                ]);
            }
        });
    }

    protected function createAuditEntry(string $type, array $data): AuditLog
    {
        $entry = new AuditLog([
            'type' => $type,
            'data' => json_encode($data),
            'created_at' => Carbon::now(),
            'system_id' => $this->systemId
        ]);

        $entry->save();
        $this->logToExternalSystems($type, $data);

        return $entry;
    }

    protected function handleFailureEscalation(AuditLog $entry, \Exception $e): void
    {
        if ($this->requiresEscalation($e)) {
            $this->alerts->triggerCriticalAlert([
                'audit_id' => $entry->id,
                'error' => $e->getMessage(),
                'severity' => 'critical',
                'requires_immediate_action' => true
            ]);

            Log::critical('Operation failure requiring escalation', [
                'audit_id' => $entry->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    protected function logToExternalSystems(string $type, array $data): void
    {
        if ($this->config->hasExternalLogging()) {
            try {
                $this->sendToExternalLogSystem($type, $data);
            } catch (\Exception $e) {
                Log::error('Failed to send to external log system', [
                    'type' => $type,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    protected function captureSystemState(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'cpu_usage' => sys_getloadavg(),
            'db_stats' => DB::connection()->getQueryLog(),
            'timestamp' => microtime(true)
        ];
    }

    protected function isSecurityCritical(\Exception $e): bool
    {
        return $e instanceof SecurityException || 
               $e->getCode() >= $this->config->getCriticalErrorCode();
    }

    protected function requiresEscalation(\Exception $e): bool
    {
        return $this->isSecurityCritical($e) || 
               $this->exceedsFailureThreshold();
    }

    protected function isHighSeverityEvent(string $type, array $data): bool
    {
        return in_array($type, $this->config->getHighSeverityEvents()) ||
               ($data['severity'] ?? 'low') === 'high';
    }

    protected function isFailedAccessSuspicious(array $context): bool
    {
        return $this->exceedsFailedAccessThreshold($context) ||
               $this->matchesSuspiciousPattern($context);
    }

    protected function exceedsFailureThreshold(): bool
    {
        $recentFailures = DB::table('audit_logs')
            ->where('type', 'operation_failure')
            ->where('created_at', '>=', Carbon::now()->subMinutes(5))
            ->count();

        return $recentFailures >= $this->config->getFailureThreshold();
    }

    protected function exceedsFailedAccessThreshold(array $context): bool
    {
        $recentFailures = DB::table('audit_logs')
            ->where('type', 'access_attempt')
            ->where('data->context->ip', $context['ip'])
            ->where('created_at', '>=', Carbon::now()->subHour())
            ->where('data->success', false)
            ->count();

        return $recentFailures >= $this->config->getFailedAccessThreshold();
    }

    protected function matchesSuspiciousPattern(array $context): bool
    {
        foreach ($this->config->getSuspiciousPatterns() as $pattern) {
            if (preg_match($pattern, json_encode($context))) {
                return true;
            }
        }
        return false;
    }

    protected function sendToExternalLogSystem(string $type, array $data): void
    {
        // Implementation depends on external logging system
    }
}
