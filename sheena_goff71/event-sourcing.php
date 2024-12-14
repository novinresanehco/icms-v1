<?php

namespace App\Core\Events\Sourcing;

class EventSourcedAggregate
{
    private string $id;
    private int $version = 0;
    private array $uncommittedEvents = [];
    private array $appliedEvents = [];

    public function __construct(string $id)
    {
        $this->id = $id;
    }

    protected function recordThat(DomainEvent $event): void
    {
        $this->applyEvent($event);
        $this->uncommittedEvents[] = $event;
    }

    public function applyEvent(DomainEvent $event): void
    {
        $handler = $this->getEventHandler($event);
        if ($handler) {
            $this->$handler($event);
        }
        $this->appliedEvents[] = $event;
        $this->version++;
    }

    private function getEventHandler(DomainEvent $event): ?string
    {
        $parts = explode('\\', get_class($event));
        $className = end($parts);
        return method_exists($this, "apply{$className}") ? "apply{$className}" : null;
    }

    public function getUncommittedEvents(): array
    {
        return $this->uncommittedEvents;
    }

    public function clearUncommittedEvents(): void
    {
        $this->uncommittedEvents = [];
    }

    public function getVersion(): int
    {
        return $this->version;
    }
}

interface DomainEvent
{
    public function getAggregateId(): string;
    public function getEventData(): array;
    public function getOccurredOn(): \DateTimeImmutable;
}

class AggregateRepository
{
    private EventStore $eventStore;
    private array $aggregates = [];

    public function __construct(EventStore $eventStore)
    {
        $this->eventStore = $eventStore;
    }

    public function save(EventSourcedAggregate $aggregate): void
    {
        $events = $aggregate->getUncommittedEvents();
        $this->eventStore->append($aggregate->getId(), $events);
        $aggregate->clearUncommittedEvents();
    }

    public function load(string $aggregateId): EventSourcedAggregate
    {
        if (isset($this->aggregates[$aggregateId])) {
            return $this->aggregates[$aggregateId];
        }

        $events = $this->eventStore->getEventsForAggregate($aggregateId);
        $aggregate = $this->reconstituteAggregate($aggregateId, $events);
        
        $this->aggregates[$aggregateId] = $aggregate;
        return $aggregate;
    }

    private function reconstituteAggregate(string $id, array $events): EventSourcedAggregate
    {
        $aggregate = new EventSourcedAggregate($id);
        foreach ($events as $event) {
            $aggregate->applyEvent($event);
        }
        return $aggregate;
    }
}

class EventSourcingHandler
{
    private AggregateRepository $repository;
    private EventPublisher $publisher;

    public function __construct(AggregateRepository $repository, EventPublisher $publisher)
    {
        $this->repository = $repository;
        $this->publisher = $publisher;
    }

    public function handleCommand(Command $command): void
    {
        $aggregate = $this->repository->load($command->getAggregateId());
        $aggregate->handle($command);
        
        $this->repository->save($aggregate);
        
        foreach ($aggregate->getUncommittedEvents() as $event) {
            $this->publisher->publish($event);
        }
    }
}

class Snapshot
{
    private string $aggregateId;
    private int $version;
    private array $state;
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $aggregateId,
        int $version,
        array $state,
        \DateTimeImmutable $createdAt
    ) {
        $this->aggregateId = $aggregateId;
        $this->version = $version;
        $this->state = $state;
        $this->createdAt = $createdAt;
    }

    public function getAggregateId(): string
    {
        return $this->aggregateId;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getState(): array
    {
        return $this->state;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

class SnapshotStore
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function save(Snapshot $snapshot): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO snapshots (aggregate_id, version, state, created_at) 
             VALUES (?, ?, ?, ?)'
        );

        $stmt->execute([
            $snapshot->getAggregateId(),
            $snapshot->getVersion(),
            json_encode($snapshot->getState()),
            $snapshot->getCreatedAt()->format('Y-m-d H:i:s')
        ]);
    }

    public function get(string $aggregateId): ?Snapshot
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM snapshots 
             WHERE aggregate_id = ? 
             ORDER BY version DESC 
             LIMIT 1'
        );

        $stmt->execute([$aggregateId]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$data) {
            return null;
        }

        return new Snapshot(
            $data['aggregate_id'],
            $data['version'],
            json_decode($data['state'], true),
            new \DateTimeImmutable($data['created_at'])
        );
    }
}

class SnapshotStrategy
{
    private int $threshold;

    public function __construct(int $threshold = 100)
    {
        $this->threshold = $threshold;
    }

    public function shouldTakeSnapshot(EventSourcedAggregate $aggregate): bool
    {
        return $aggregate->getVersion() % $this->threshold === 0;
    }
}

class EventSourcedAggregateRoot extends EventSourcedAggregate
{
    private array $handlers = [];
    private SnapshotStore $snapshotStore;
    private SnapshotStrategy $snapshotStrategy;

    public function __construct(
        string $id,
        SnapshotStore $snapshotStore,
        SnapshotStrategy $snapshotStrategy
    ) {
        parent::__construct($id);
        $this->snapshotStore = $snapshotStore;
        $this->snapshotStrategy = $snapshotStrategy;
    }

    public function handle(Command $command): void
    {
        $handler = $this->getCommandHandler($command);
        if ($handler) {
            $this->$handler($command);
        }

        if ($this->snapshotStrategy->shouldTakeSnapshot($this)) {
            $this->takeSnapshot();
        }
    }

    private function takeSnapshot(): void
    {
        $snapshot = new Snapshot(
            $this->getId(),
            $this->getVersion(),
            $this->getState(),
            new \DateTimeImmutable()
        );

        $this->snapshotStore->save($snapshot);
    }

    protected function getState(): array
    {
        return [
            'id' => $this->getId(),
            'version' => $this->getVersion(),
            // Add other state properties
        ];
    }

    private function getCommandHandler(Command $command): ?string
    {
        $parts = explode('\\', get_class($command));
        $className = end($parts);
        return method_exists($this, "handle{$className}") ? "handle{$className}" : null;
    }
}
