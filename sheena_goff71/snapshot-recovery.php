<?php

namespace App\Core\Events\Snapshots\Recovery;

class SnapshotRecoveryManager
{
    private SnapshotStore $snapshotStore;
    private EventStore $eventStore;
    private RecoveryValidator $validator;
    private RecoveryLogger $logger;

    public function __construct(
        SnapshotStore $snapshotStore,
        EventStore $eventStore,
        RecoveryValidator $validator,
        RecoveryLogger $logger
    ) {
        $this->snapshotStore = $snapshotStore;
        $this->eventStore = $eventStore;
        $this->validator = $validator;
        $this->logger = $logger;
    }

    public function recoverAggregate(string $aggregateId, ?int $targetVersion = null): RecoveryResult
    {
        $this->logger->startRecovery($aggregateId, $targetVersion);

        try {
            $snapshot = $this->findBestSnapshot($aggregateId, $targetVersion);
            if (!$snapshot) {
                throw new RecoveryException("No suitable snapshot found for recovery");
            }

            $aggregate = $this->rebuildFromSnapshot($snapshot);
            
            if ($targetVersion !== null) {
                $aggregate = $this->replayToVersion($aggregate, $targetVersion);
            }

            $this->validator->validateRecoveredAggregate($aggregate);
            
            $result = new RecoveryResult($aggregate, $snapshot);
            $this->logger->completeRecovery($result);
            
            return $result;

        } catch (\Exception $e) {
            $this->logger->recoveryError($e);
            throw new RecoveryException("Recovery failed: " . $e->getMessage(), 0, $e);
        }
    }

    private function findBestSnapshot(string $aggregateId, ?int $targetVersion): ?Snapshot
    {
        if ($targetVersion === null) {
            return $this->snapshotStore->getLatest($aggregateId);
        }

        return $this->snapshotStore->findClosestToVersion($aggregateId, $targetVersion);
    }

    private function rebuildFromSnapshot(Snapshot $snapshot): AggregateRoot
    {
        $aggregate = AggregateRoot::fromSnapshot($snapshot);
        $this->validator->validateSnapshotState($aggregate);
        return $aggregate;
    }

    private function replayToVersion(AggregateRoot $aggregate, int $targetVersion): AggregateRoot
    {
        $events = $this->eventStore->getEventsForAggregateBetweenVersions(
            $aggregate->getId(),
            $aggregate->getVersion(),
            $targetVersion
        );

        foreach ($events as $event) {
            $aggregate->apply($event);
            $this->validator->validateEventApplication($aggregate, $event);
        }

        return $aggregate;
    }
}

class RecoveryResult
{
    private AggregateRoot $aggregate;
    private Snapshot $snapshot;
    private array $metrics;
    private \DateTimeImmutable $completedAt;

    public function __construct(AggregateRoot $aggregate, Snapshot $snapshot)
    {
        $this->aggregate = $aggregate;
        $this->snapshot = $snapshot;
        $this->completedAt = new \DateTimeImmutable();
        $this->metrics = [
            'start_version' => $snapshot->getVersion(),
            'final_version' => $aggregate->getVersion(),
            'events_replayed' => $aggregate->getVersion() - $snapshot->getVersion()
        ];
    }

    public function getAggregate(): AggregateRoot
    {
        return $this->aggregate;
    }

    public function getSnapshot(): Snapshot
    {
        return $this->snapshot;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    public function getCompletedAt(): \DateTimeImmutable
    {
        return $this->completedAt;
    }
}

class RecoveryValidator
{
    private array $stateValidators = [];
    private array $consistencyChecks = [];

    public function registerStateValidator(string $aggregateType, callable $validator): void
    {
        $this->stateValidators[$aggregateType] = $validator;
    }

    public function registerConsistencyCheck(string $aggregateType, callable $check): void
    {
        $this->consistencyChecks[$aggregateType] = $check;
    }

    public function validateSnapshotState(AggregateRoot $aggregate): void 
    {
        $type = get_class($aggregate);
        
        if (isset($this->stateValidators[$type])) {
            $validator = $this->stateValidators[$type];
            if (!$validator($aggregate)) {
                throw new RecoveryValidationException("Invalid aggregate state after snapshot recovery");
            }
        }
    }

    public function validateEventApplication(AggregateRoot $aggregate, Event $event): void
    {
        $type = get_class($aggregate);
        
        if (isset($this->consistencyChecks[$type])) {
            $check = $this->consistencyChecks[$type];
            if (!$check($aggregate, $event)) {
                throw new RecoveryValidationException(
                    "Consistency check failed after applying event " . get_class($event)
                );
            }
        }
    }

    public function validateRecoveredAggregate(AggregateRoot $aggregate): void
    {
        $this->validateSnapshotState($aggregate);
        
        $type = get_class($aggregate);
        if (isset($this->consistencyChecks[$type])) {
            $check = $this->consistencyChecks[$type];
            if (!$check($aggregate, null)) {
                throw new RecoveryValidationException("Final consistency check failed");
            }
        }
    }
}

class RecoveryException extends \Exception {}
class RecoveryValidationException extends RecoveryException {}

class RecoveryLogger
{
    private LoggerInterface $logger;
    private MetricsCollector $metrics;

    public function __construct(LoggerInterface $logger, MetricsCollector $metrics)
    {
        $this->logger = $logger;
        $this->metrics = $metrics;
    }

    public function startRecovery(string $aggregateId, ?int $targetVersion): void
    {
        $this->logger->info('Starting aggregate recovery', [
            'aggregate_id' => $aggregateId,
            'target_version' => $targetVersion
        ]);

        $this->metrics->increment('snapshot.recovery.started', [
            'aggregate_id' => $aggregateId
        ]);
    }

    public function completeRecovery(RecoveryResult $result): void
    {
        $this->logger->info('Recovery completed', [
            'aggregate_id' => $result->getAggregate()->getId(),
            'start_version' => $result->getSnapshot()->getVersion(),
            'final_version' => $result->getAggregate()->getVersion(),
            'events_replayed' => $result->getMetrics()['events_replayed']
        ]);

        $this->metrics->increment('snapshot.recovery.completed');
        $this->metrics->gauge('snapshot.recovery.events_replayed', 
            $result->getMetrics()['events_replayed']
        );
    }

    public function recoveryError(\Exception $e): void
    {
        $this->logger->error('Recovery failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->metrics->increment('snapshot.recovery.failed');
    }
}
