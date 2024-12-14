<?php

namespace App\Core\Audit;

class AuditSystem implements AuditInterface
{
    protected LogManager $logs;
    protected SecurityManager $security;
    protected MetricsCollector $metrics;
    protected EventDispatcher $events;

    public function logSecurityEvent(SecurityEvent $event): void
    {
        DB::beginTransaction();
        try {
            $record = $this->createAuditRecord($event);
            $this->validateAndEnrich($record);
            $this->storeRecord($record);
            
            if ($this->isCriticalEvent($event)) {
                $this->handleCriticalEvent($event, $record);
            }
            
            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleAuditFailure($e, $event);
            throw new AuditException('Audit logging failed', 0, $e);
        }
    }

    public function trackOperation(Operation $operation, array $context): void
    {
        $startTime = microtime(true);
        
        try {
            $record = $this->createOperationRecord($operation, $context);
            $record->duration = microtime(true) - $startTime;
            
            $this->storeRecord($record);
            $this->metrics->recordOperationMetrics($operation, $record->duration);
            
        } catch (\Exception $e) {
            $this->handleAuditFailure($e, $operation);
        }
    }

    protected function createAuditRecord(SecurityEvent $event): AuditRecord
    {
        return new AuditRecord([
            'event_type' => $event->getType(),
            'severity' => $event->getSeverity(),
            'user_id' => $event->getUserId(),
            'resource_id' => $event->getResourceId(),
            'ip_address' => $event->getIpAddress(),
            'user_agent' => $event->getUserAgent(),
            'timestamp' => now(),
            'context' => $this->serializeContext($event->getContext())
        ]);
    }

    protected function validateAndEnrich(AuditRecord $record): void
    {
        $record->hash = $this->generateRecordHash($record);
        $record->signature = $this->security->signRecord($record);
        $record->metadata = $this->collectMetadata();
    }

    protected function storeRecord(AuditRecord $record): void
    {
        $this->logs->store($record);
        
        if ($record->severity >= SecurityLevel::HIGH) {
            $this->logs->replicateToSecureStorage($record);
        }
        
        $this->events->dispatch(new AuditRecordCreated($record));
    }

    protected function handleCriticalEvent(SecurityEvent $event, AuditRecord $record): void
    {
        $this->security->validateEventIntegrity($event);
        $this->notifySecurityTeam($event, $record);
        
        if ($this->requiresImmediateAction($event)) {
            $this->executeEmergencyProtocol($event);
        }
    }

    protected function generateRecordHash(AuditRecord $record): string
    {
        return hash_hmac('sha256', 
            $record->toJson(), 
            config('audit.hash_key')
        );
    }

    protected function collectMetadata(): array
    {
        return [
            'system_load' => sys_getloadavg(),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'process_id' => getmypid(),
            'server_time' => microtime(true)
        ];
    }

    protected function handleAuditFailure(\Exception $e, $context): void
    {
        $emergency = new EmergencyLog();
        $emergency->logFailure($e, $context);
        
        $this->metrics->increment('audit.failure');
        $this->events->dispatch(new AuditFailureEvent($e, $context));
    }

    protected function isCriticalEvent(SecurityEvent $event): bool
    {
        return $event->getSeverity() >= SecurityLevel::CRITICAL ||
               in_array($event->getType(), [
                   SecurityEventType::INTRUSION_ATTEMPT,
                   SecurityEventType::DATA_BREACH,
                   SecurityEventType::AUTHENTICATION_BREACH
               ]);
    }

    protected function requiresImmediateAction(SecurityEvent $event): bool
    {
        return $event->getSeverity() === SecurityLevel::EMERGENCY ||
               $event->requiresImmediateResponse();
    }

    protected function executeEmergencyProtocol(SecurityEvent $event): void
    {
        $protocol = new EmergencyProtocol($event);
        $protocol->execute();
        
        $this->events->dispatch(new EmergencyProtocolExecuted($event));
    }

    protected function serializeContext(array $context): string
    {
        return json_encode(array_filter($context, function($value) {
            return !$this->isExcludedValue($value);
        }));
    }

    protected function isExcludedValue($value): bool
    {
        return $value instanceof \Closure ||
               $value instanceof \Resource ||
               is_resource($value);
    }
}
