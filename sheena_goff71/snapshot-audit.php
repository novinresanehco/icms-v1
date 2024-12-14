<?php

namespace App\Core\Events\Snapshots\Audit;

class SnapshotAuditor
{
    private AuditStore $store;
    private AuditLogger $logger;
    private IntegrityChecker $integrityChecker;
    private AuditMetrics $metrics;

    public function __construct(
        AuditStore $store,
        AuditLogger $logger,
        IntegrityChecker $integrityChecker,
        AuditMetrics $metrics
    ) {
        $this->store = $store;
        $this->logger = $logger;
        $this->integrityChecker = $integrityChecker;
        $this->metrics = $metrics;
    }

    public function auditSnapshot(Snapshot $snapshot): AuditResult
    {
        $this->logger->startAudit($snapshot);
        $startTime = microtime(true);

        try {
            // Verify snapshot integrity
            $integrityResult = $this->integrityChecker->verify($snapshot);
            
            // Create audit record
            $audit = new AuditRecord(
                $snapshot->getAggregateId(),
                $snapshot->getVersion(),
                $integrityResult,
                new \DateTimeImmutable()
            );

            // Store audit record
            $this->store->save($audit);

            $result = new AuditResult($snapshot, $audit, $integrityResult);
            
            $this->metrics->recordAudit(
                $snapshot,
                $result,
                microtime(true) - $startTime
            );

            $this->logger->completeAudit($result);

            return $result;

        } catch (\Exception $e) {
            $this->handleAuditFailure($snapshot, $e);
            throw $e;
        }
    }

    public function getAuditHistory(string $aggregateId): AuditHistory
    {
        $records = $this->store->getRecordsForAggregate($aggregateId);
        return new AuditHistory($aggregateId, $records);
    }

    private function handleAuditFailure(Snapshot $snapshot, \Exception $e): void
    {
        $this->logger->auditError($snapshot, $e);
        $this->metrics->recordAuditFailure($snapshot, $e);
    }
}

class IntegrityChecker
{
    private HashValidator $hashValidator;
    private StateValidator $stateValidator;
    private array $customChecks = [];

    public function verify(Snapshot $snapshot): IntegrityResult
    {
        $results = [];

        // Verify hash
        $results['hash'] = $this->hashValidator->validate($snapshot);

        // Verify state
        $results['state'] = $this->stateValidator->validate($snapshot);

        // Run custom checks
        foreach ($this->customChecks as $name => $check) {
            $results[$name] = $check($snapshot);
        }

        return new IntegrityResult($results);
    }

    public function addCustomCheck(string $name, callable $check): void
    {
        $this->customChecks[$name] = $check;
    }
}

class AuditRecord
{
    private string $id;
    private string $aggregateId;
    private int $version;
    private IntegrityResult $integrityResult;
    private \DateTimeImmutable $timestamp;
    private array $metadata;

    public function __construct(
        string $aggregateId,
        int $version,
        IntegrityResult $integrityResult,
        \DateTimeImmutable $timestamp,
        array $metadata = []
    ) {
        $this->id = uniqid('audit_', true);
        $this->aggregateId = $aggregateId;
        $this->version = $version;
        $this->integrityResult = $integrityResult;
        $this->timestamp = $timestamp;
        $this->metadata = $metadata;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getAggregateId(): string
    {
        return $this->aggregateId;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getIntegrityResult(): IntegrityResult
    {
        return $this->integrityResult;
    }

    public function getTimestamp(): \DateTimeImmutable
    {
        return $this->timestamp;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}

class IntegrityResult
{
    private array $results;
    private bool $isValid;

    public function __construct(array $results)
    {
        $this->results = $results;
        $this->isValid = !in_array(false, $results, true);
    }

    public function isValid(): bool
    {
        return $this->isValid;
    }

    public function getResults(): array
    {
        return $this->results;
    }

    public function getFailures(): array
    {
        return array_filter(
            $this->results,
            fn($result) => $result === false
        );
    }
}

class AuditResult
{
    private Snapshot $snapshot;
    private AuditRecord $record;
    private IntegrityResult $integrityResult;

    public function __construct(
        Snapshot $snapshot,
        AuditRecord $record,
        IntegrityResult $integrityResult
    ) {
        $this->snapshot = $snapshot;
        $this->record = $record;
        $this->integrityResult = $integrityResult;
    }

    public function getSnapshot(): Snapshot
    {
        return $this->snapshot;
    }

    public function getRecord(): AuditRecord
    {
        return $this->record;
    }

    public function getIntegrityResult(): IntegrityResult
    {
        return $this->integrityResult;
    }

    public function isValid(): bool
    {
        return $this->integrityResult->isValid();
    }
}

class AuditHistory
{
    private string $aggregateId;
    private array $records;

    public function __construct(string $aggregateId, array $records)
    {
        $this->aggregateId = $aggregateId;
        $this->records = $records;
    }

    public function getAggregateId(): string
    {
        return $this->aggregateId;
    }

    public function getRecords(): array
    {
        return $this->records;
    }

    public function hasFailures(): bool
    {
        return (bool)array_filter(
            $this->records,
            fn($record) => !$record->getIntegrityResult()->isValid()
        );
    }

    public function getFailures(): array
    {
        return array_filter(
            $this->records,
            fn($record) => !$record->getIntegrityResult()->isValid()
        );
    }
}

class AuditMetrics
{
    private MetricsCollector $collector;

    public function __construct(MetricsCollector $collector)
    {
        $this->collector = $collector;
    }

    public function recordAudit(Snapshot $snapshot, AuditResult $result, float $duration): void
    {
        $this->collector->timing('snapshot.audit.duration', $duration * 1000, [
            'aggregate_id' => $snapshot->getAggregateId()
        ]);

        $this->collector->increment('snapshot.audit.completed', [
            'valid' => $result->isValid() ? 'true' : 'false'
        ]);
    }

    public function recordAuditFailure(Snapshot $snapshot, \Exception $error): void
    {
        $this->collector->increment('snapshot.audit.failed', [
            'aggregate_id' => $snapshot->getAggregateId(),
            'error_type' => get_class($error)
        ]);
    }
}

class AuditException extends \Exception {}

