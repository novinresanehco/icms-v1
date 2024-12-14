<?php

namespace App\Core\Audit;

class AuditService implements AuditInterface
{
    private EventCollector $eventCollector;
    private HashGenerator $hashGenerator;
    private IntegrityValidator $integrityValidator;
    private StorageManager $storageManager;
    private MetricsCollector $metrics;
    private AlertSystem $alerts;

    public function __construct(
        EventCollector $eventCollector,
        HashGenerator $hashGenerator,
        IntegrityValidator $integrityValidator,
        StorageManager $storageManager,
        MetricsCollector $metrics,
        AlertSystem $alerts
    ) {
        $this->eventCollector = $eventCollector;
        $this->hashGenerator = $hashGenerator;
        $this->integrityValidator = $integrityValidator;
        $this->storageManager = $storageManager;
        $this->metrics = $metrics;
        $this->alerts = $alerts;
    }

    public function recordAuditEvent(AuditEvent $event): AuditRecord
    {
        $recordId = $this->initializeRecord($event);
        
        try {
            DB::beginTransaction();
            
            $this->validateEvent($event);
            $hash = $this->generateEventHash($event);
            $metadata = $this->collectEventMetadata($event);
            
            $record = new AuditRecord([
                'event' => $event,
                'hash' => $hash,
                'metadata' => $metadata,
                'record_id' => $recordId
            ]);
            
            $this->verifyRecordIntegrity($record);
            $this->storeAuditRecord($record);
            
            DB::commit();
            return $record;

        } catch (AuditException $e) {
            DB::rollBack();
            $this->handleAuditFailure($e, $recordId);
            throw new CriticalAuditException($e->getMessage(), $e);
        }
    }

    private function validateEvent(AuditEvent $event): void
    {
        if (!$this->integrityValidator->validateEvent($event)) {
            throw new ValidationException('Audit event validation failed');
        }
    }

    private function generateEventHash(AuditEvent $event): string
    {
        return $this->hashGenerator->generateHash([
            'event_data' => $event->getData(),
            'timestamp' => $event->getTimestamp(),
            'actor' => $event->getActor(),
            'context' => $event->getContext()
        ]);
    }

    private function verifyRecordIntegrity(AuditRecord $record): void
    {
        if (!$this->integrityValidator->verifyRecord($record)) {
            throw new IntegrityException('Audit record integrity verification failed');
        }
    }

    private function storeAuditRecord(AuditRecord $record): void
    {
        $this->storageManager->store($record);
        $this->metrics->recordAuditEvent($record);
    }

    private function handleAuditFailure(AuditException $e, string $recordId): void
    {
        $this->storageManager->logFailure($e, $recordId);
        
        $this->alerts->dispatch(
            new AuditAlert(
                'Critical audit failure',
                [
                    'record_id' => $recordId,
                    'exception' => $e
                ]
            )
        );
        
        $this->metrics->recordFailure('audit', [
            'record_id' => $recordId,
            'error' => $e->getMessage()
        ]);
    }
}
