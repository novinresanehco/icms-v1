<?php

namespace App\Core\Security;

use Illuminate\Support\Facades\{DB, Log, Cache};
use App\Core\Monitoring\MetricsCollector;

class AuditSystem
{
    private MetricsCollector $metrics;
    private SecurityConfig $config;
    private array $criticalEvents;
    private string $currentOperationId;

    public function logSecurityEvent(SecurityEvent $event): void
    {
        DB::beginTransaction();
        
        try {
            $eventData = $this->prepareEventData($event);
            $this->validateEventData($eventData);
            
            if ($this->isCriticalEvent($event)) {
                $this->handleCriticalEvent($event);
            }
            
            $this->persistAuditLog($eventData);
            $this->updateMetrics($event);
            
            if ($event->requiresNotification()) {
                $this->notifySecurityTeam($event);
            }
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAuditFailure($e, $event);
            throw $e;
        }
    }

    public function startOperation(string $type): string 
    {
        $this->currentOperationId = $this->generateOperationId();
        
        $this->logOperationStart([
            'operation_id' => $this->currentOperationId,
            'type' => $type,
            'timestamp' => microtime(true),
            'context' => $this->captureContext()
        ]);
        
        return $this->currentOperationId;
    }

    public function endOperation(string $operationId, array $result): void
    {
        $this->validateOperationId($operationId);
        
        $this->logOperationEnd([
            'operation_id' => $operationId,
            'result' => $result,
            'duration' => $this->calculateDuration($operationId),
            'metrics' => $this->collectMetrics($operationId)
        ]);
    }

    public function logFailure(\Exception $e, array $context = []): void
    {
        $failureData = [
            'exception' => [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ],
            'context' => $context,
            'system_state' => $this->captureSystemState(),
            'timestamp' => microtime(true)
        ];

        if ($this->isCriticalFailure($e)) {
            $this->handleCriticalFailure($failureData);
        }

        $this->persistFailureLog($failureData);
        $this->updateFailureMetrics($e);
    }

    private function prepareEventData(SecurityEvent $event): array
    {
        return [
            'event_id' => $this->generateEventId(),
            'type' => $event->getType(),
            'severity' => $event->getSeverity(),
            'timestamp' => microtime(true),
            'data' => $event->getData(),
            'context' => $this->captureContext(),
            'operation_id' => $this->currentOperationId ?? null
        ];
    }

    private function validateEventData(array $data): void
    {
        $requiredFields = ['event_id', 'type', 'severity', 'timestamp', 'data'];
        
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                throw new AuditException("Missing required field: {$field}");
            }
        }
    }

    private function persistAuditLog(array $data): void
    {
        DB::table('security_audit_log')->insert([
            'event_data' => json_encode($data),
            'created_at' => now(),
            'hash' => $this->generateEventHash($data)
        ]);

        if ($this->config->isRedundantLoggingEnabled()) {
            $this->writeToSecondaryLog($data);
        }
    }

    private function handleCriticalEvent(SecurityEvent $event): void
    {
        Cache::tags(['security', 'critical'])->put(
            "critical_event:{$event->getId()}", 
            $event->getData(),
            $this->config->getCriticalEventTTL()
        );

        $this->notifySecurityTeam($event);
        $this->triggerAutomatedResponse($event);
    }

    private function captureContext(): array
    {
        return [
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'cpu_usage' => sys_getloadavg()[0],
            'request_id' => request()->id() ?? null,
            'user_id' => auth()->id() ?? null,
            'ip_address' => request()->ip() ?? null
        ];
    }

    private function generateEventHash(array $data): string
    {
        return hash_hmac(
            'sha256', 
            json_encode($data), 
            $this->config->getAuditSecret()
        );
    }

    private function updateMetrics(SecurityEvent $event): void
    {
        $this->metrics->increment("security_events.{$event->getType()}");
        $this->metrics->gauge('security_events.severity', $event->getSeverity());
        
        if ($event->hasPerformanceImpact()) {
            $this->metrics->recordTiming(
                'security_events.processing_time',
                $event->getProcessingTime()
            );
        }
    }

    private function writeToSecondaryLog(array $data): void
    {
        foreach ($this->config->getSecondaryLogTargets() as $target) {
            try {
                $target->write($data);
            } catch (\Exception $e) {
                Log::error("Secondary log write failed: {$e->getMessage()}");
            }
        }
    }
}
