<?php

namespace App\Core\Services;

use Illuminate\Support\Facades\{DB, Log, Cache};
use App\Core\Interfaces\AuditInterface;
use App\Core\Security\{Encryption, SecurityMetrics};

class AuditService implements AuditInterface 
{
    private Encryption $encryption;
    private SecurityMetrics $metrics;
    private string $systemId;
    private array $criticalEvents;

    public function __construct(
        Encryption $encryption,
        SecurityMetrics $metrics,
        string $systemId,
        array $criticalEvents
    ) {
        $this->encryption = $encryption;
        $this->metrics = $metrics;
        $this->systemId = $systemId;
        $this->criticalEvents = $criticalEvents;
    }

    public function logOperation(string $operation, array $context, array $metadata = []): void 
    {
        $auditRecord = [
            'timestamp' => microtime(true),
            'operation' => $operation,
            'context' => $this->sanitizeContext($context),
            'metadata' => $metadata,
            'system_id' => $this->systemId,
            'trace_id' => $this->generateTraceId()
        ];

        $this->storeAuditRecord($auditRecord);
        $this->metrics->incrementOperationCount($operation);

        if ($this->isCriticalOperation($operation)) {
            $this->handleCriticalOperation($auditRecord);
        }
    }

    public function logSecurityEvent(string $event, array $context, int $severity): void 
    {
        $securityRecord = [
            'timestamp' => microtime(true),
            'event' => $event,
            'context' => $this->sanitizeContext($context),
            'severity' => $severity,
            'system_id' => $this->systemId,
            'trace_id' => $this->generateTraceId()
        ];

        $this->storeSecurityRecord($securityRecord);
        $this->metrics->incrementSecurityEventCount($event, $severity);

        if ($this->isCriticalSeverity($severity)) {
            $this->handleCriticalSecurityEvent($securityRecord);
        }
    }

    public function logAccessAttempt(string $resource, array $context, bool $success): void 
    {
        $accessRecord = [
            'timestamp' => microtime(true),
            'resource' => $resource,
            'context' => $this->sanitizeContext($context),
            'success' => $success,
            'system_id' => $this->systemId,
            'trace_id' => $this->generateTraceId()
        ];

        $this->storeAccessRecord($accessRecord);
        $this->metrics->incrementAccessAttempt($resource, $success);

        if (!$success) {
            $this->handleFailedAccess($accessRecord);
        }
    }

    public function logSystemChange(string $component, array $changes, array $context): void 
    {
        $changeRecord = [
            'timestamp' => microtime(true),
            'component' => $component,
            'changes' => $this->encryptSensitiveChanges($changes),
            'context' => $this->sanitizeContext($context),
            'system_id' => $this->systemId,
            'trace_id' => $this->generateTraceId()
        ];

        $this->storeChangeRecord($changeRecord);
        $this->metrics->incrementChangeCount($component);

        if ($this->isCriticalComponent($component)) {
            $this->handleCriticalChange($changeRecord);
        }
    }

    public function logDataAccess(string $dataType, string $operation, array $context): void 
    {
        $dataRecord = [
            'timestamp' => microtime(true),
            'data_type' => $dataType,
            'operation' => $operation,
            'context' => $this->sanitizeContext($context),
            'system_id' => $this->systemId,
            'trace_id' => $this->generateTraceId()
        ];

        $this->storeDataAccessRecord($dataRecord);
        $this->metrics->incrementDataAccessCount($dataType, $operation);

        if ($this->isSensitiveData($dataType)) {
            $this->handleSensitiveDataAccess($dataRecord);
        }
    }

    protected function sanitizeContext(array $context): array 
    {
        return array_map(function ($value) {
            if ($this->isSensitiveField($value)) {
                return $this->encryption->encrypt($value);
            }
            return $value;
        }, $context);
    }

    protected function storeAuditRecord(array $record): void 
    {
        DB::transaction(function() use ($record) {
            DB::table('audit_log')->insert($record);
            Cache::put("audit:latest:{$record['trace_id']}", $record, now()->addDay());
        });
    }

    protected function storeSecurityRecord(array $record): void 
    {
        DB::transaction(function() use ($record) {
            DB::table('security_log')->insert($record);
            if ($this->isCriticalSeverity($record['severity'])) {
                $this->notifySecurityTeam($record);
            }
        });
    }

    protected function storeAccessRecord(array $record): void 
    {
        DB::transaction(function() use ($record) {
            DB::table('access_log')->insert($record);
            $this->updateAccessMetrics($record);
        });
    }

    protected function storeChangeRecord(array $record): void 
    {
        DB::transaction(function() use ($record) {
            DB::table('change_log')->insert($record);
            $this->trackSystemChanges($record);
        });
    }

    protected function storeDataAccessRecord(array $record): void 
    {
        DB::transaction(function() use ($record) {
            DB::table('data_access_log')->insert($record);
            $this->updateDataAccessMetrics($record);
        });
    }

    protected function handleCriticalOperation(array $record): void 
    {
        Log::critical('Critical operation executed', $record);
        $this->notifyAdministrators($record);
        $this->createIncidentReport($record);
    }

    protected function handleCriticalSecurityEvent(array $record): void 
    {
        Log::emergency('Critical security event detected', $record);
        $this->triggerSecurityAlert($record);
        $this->initializeIncidentResponse($record);
    }

    protected function handleFailedAccess(array $record): void 
    {
        $this->incrementFailedAccessCounter($record['resource'], $record['context']);
        if ($this->isFailedAccessThresholdExceeded($record['resource'])) {
            $this->triggerAccessAlert($record);
        }
    }

    protected function handleCriticalChange(array $record): void 
    {
        Log::critical('Critical system change detected', $record);
        $this->notifyChangeManagement($record);
        $this->createChangeReport($record);
    }

    protected function handleSensitiveDataAccess(array $record): void 
    {
        Log::warning('Sensitive data accessed', $record);
        $this->trackSensitiveDataAccess($record);
        $this->updateComplianceLog($record);
    }

    private function generateTraceId(): string 
    {
        return uniqid('audit_', true);
    }

    private function isCriticalOperation(string $operation): bool 
    {
        return in_array($operation, $this->criticalEvents);
    }

    private function isCriticalSeverity(int $severity): bool 
    {
        return $severity >= 8;
    }

    private function isCriticalComponent(string $component): bool 
    {
        return str_starts_with($component, 'core.') || 
               str_starts_with($component, 'security.');
    }

    private function isSensitiveData(string $dataType): bool 
    {
        return str_contains($dataType, 'sensitive') || 
               str_contains($dataType, 'personal');
    }

    private function encryptSensitiveChanges(array $changes): array 
    {
        return array_map(function ($change) {
            if ($this->isSensitiveChange($change)) {
                return $this->encryption->encrypt(json_encode($change));
            }
            return $change;
        }, $changes);
    }
}
