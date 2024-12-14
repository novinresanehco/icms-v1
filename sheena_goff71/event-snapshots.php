<?php

namespace App\Core\Events\Snapshots;

class SnapshotManager
{
    private SnapshotStore $store;
    private SnapshotStrategy $strategy;
    private EventStore $eventStore;
    private SnapshotSerializer $serializer;

    public function __construct(
        SnapshotStore $store,
        SnapshotStrategy $strategy,
        EventStore $eventStore,
        SnapshotSerializer $serializer
    ) {
        $this->store = $store;
        $this->strategy = $strategy;
        $this->eventStore = $eventStore;
        $this->serializer = $serializer;
    }

    public function createSnapshot(string $aggregateId): Snapshot
    {
        $events = $this->eventStore->getEventsForAggregate($aggregateId);
        $aggregate = $this->rebuildAggregate($aggregateId, $events);
        
        $snapshot = new Snapshot(
            $aggregateId,
            $aggregate->getVersion(),
            $this->serializer->serialize($aggregate),
            new \DateTimeImmutable()
        );

        $this->store->save($snapshot);
        return $snapshot;
    }

    public function loadFromSnapshot(string $aggregateId): ?AggregateRoot
    {
        $snapshot = $this->store->getLatest($aggregateId);
        if (!$snapshot) {
            return null;
        }

        $aggregate = $this->serializer->deserialize($snapshot->getState());
        $events = $this->eventStore->getEventsForAggregateSinceVersion(
            $aggregateId,
            $snapshot->getVersion()
        );

        foreach ($events as $event) {
            $aggregate->apply($event);
        }

        return $aggregate;
    }

    public function shouldTakeSnapshot(AggregateRoot $aggregate): bool
    {
        return $this->strategy->shouldTakeSnapshot($aggregate);
    }

    private function rebuildAggregate(string $aggregateId, array $events): AggregateRoot
    {
        $aggregate = new AggregateRoot($aggregateId);
        foreach ($events as $event) {
            $aggregate->apply($event);
        }
        return $aggregate;
    }
}

class Snapshot
{
    private string $aggregateId;
    private int $version;
    private string $state;
    private \DateTimeImmutable $createdAt;
    private array $metadata;

    public function __construct(
        string $aggregateId,
        int $version,
        string $state,
        \DateTimeImmutable $createdAt,
        array $metadata = []
    ) {
        $this->aggregateId = $aggregateId;
        $this->version = $version;
        $this->state = $state;
        $this->createdAt = $createdAt;
        $this->metadata = $metadata;
    }

    public function getAggregateId(): string
    {
        return $this->aggregateId;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}

class SnapshotStore
{
    private \PDO $pdo;
    private string $table;

    public function __construct(\PDO $pdo, string $table = 'snapshots')
    {
        $this->pdo = $pdo;
        $this->table = $table;
    }

    public function save(Snapshot $snapshot): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->table} 
            (aggregate_id, version, state, metadata, created_at)
            VALUES (?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $snapshot->getAggregateId(),
            $snapshot->getVersion(),
            $snapshot->getState(),
            json_encode($snapshot->getMetadata()),
            $snapshot->getCreatedAt()->format('Y-m-d H:i:s')
        ]);
    }

    public function getLatest(string $aggregateId): ?Snapshot
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM {$this->table}
            WHERE aggregate_id = ?
            ORDER BY version DESC
            LIMIT 1
        ");

        $stmt->execute([$aggregateId]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$data) {
            return null;
        }

        return new Snapshot(
            $data['aggregate_id'],
            $data['version'],
            $data['state'],
            new \DateTimeImmutable($data['created_at']),
            json_decode($data['metadata'], true)
        );
    }

    public function deleteOldSnapshots(string $aggregateId, int $keepLast = 5): void
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM {$this->table}
            WHERE aggregate_id = ?
            AND version NOT IN (
                SELECT version FROM {$this->table}
                WHERE aggregate_id = ?
                ORDER BY version DESC
                LIMIT ?
            )
        ");

        $stmt->execute([$aggregateId, $aggregateId, $keepLast]);
    }
}

class SnapshotStrategy
{
    private int $eventsThreshold;

    public function __construct(int $eventsThreshold = 100)
    {
        $this->eventsThreshold = $eventsThreshold;
    }

    public function shouldTakeSnapshot(AggregateRoot $aggregate): bool
    {
        return $aggregate->getVersion() % $this->eventsThreshold === 0;
    }
}

class SnapshotSerializer
{
    private array $transformers = [];

    public function registerTransformer(string $type, callable $transformer): void
    {
        $this->transformers[$type] = $transformer;
    }

    public function serialize(AggregateRoot $aggregate): string
    {
        $state = $aggregate->getState();
        
        if (isset($this->transformers[get_class($aggregate)])) {
            $state = ($this->transformers[get_class($aggregate)])($state);
        }

        return json_encode([
            'class' => get_class($aggregate),
            'state' => $state,
            'version' => $aggregate->getVersion()
        ]);
    }

    public function deserialize(string $serialized): AggregateRoot
    {
        $data = json_decode($serialized, true);
        $class = $data['class'];
        
        if (!class_exists($class)) {
            throw new \RuntimeException("Class {$class} not found");
        }

        return $class::fromState($data['state'], $data['version']);
    }
}

class SnapshotMetadata
{
    private array $data;

    public function __construct(array $data = [])
    {
        $this->data = array_merge([
            'created_by' => null,
            'reason' => null,
            'environment' => null,
            'application_version' => null
        ], $data);
    }

    public function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, $value): void
    {
        $this->data[$key] = $value;
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
