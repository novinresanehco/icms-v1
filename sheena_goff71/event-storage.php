<?php

namespace App\Core\Events\Storage;

class EventStore
{
    private StorageAdapter $adapter;
    private EventSerializer $serializer;
    private StreamManager $streamManager;

    public function __construct(
        StorageAdapter $adapter,
        EventSerializer $serializer,
        StreamManager $streamManager
    ) {
        $this->adapter = $adapter;
        $this->serializer = $serializer;
        $this->streamManager = $streamManager;
    }

    public function store(Event $event, string $streamId): void
    {
        $serializedEvent = $this->serializer->serialize($event);
        $stream = $this->streamManager->getStream($streamId);
        
        $this->adapter->append($stream, [
            'event_type' => get_class($event),
            'payload' => $serializedEvent,
            'metadata' => [
                'timestamp' => time(),
                'stream_id' => $streamId,
                'sequence' => $stream->getNextSequence()
            ]
        ]);
    }

    public function getStream(string $streamId, int $fromSequence = 0): EventStream
    {
        $events = $this->adapter->read($streamId, $fromSequence);
        return new EventStream($streamId, array_map(
            fn($event) => $this->serializer->deserialize($event),
            $events
        ));
    }

    public function replayStream(string $streamId, EventHandler $handler): void
    {
        $stream = $this->getStream($streamId);
        foreach ($stream as $event) {
            $handler->handle($event);
        }
    }
}

class EventSerializer
{
    private array $transformers = [];

    public function registerTransformer(string $eventType, callable $transformer): void
    {
        $this->transformers[$eventType] = $transformer;
    }

    public function serialize(Event $event): string
    {
        $data = [
            'type' => get_class($event),
            'data' => $event->getData(),
            'metadata' => [
                'timestamp' => $event->getTime(),
                'version' => '1.0'
            ]
        ];

        if (isset($this->transformers[get_class($event)])) {
            $data = ($this->transformers[get_class($event)])($data);
        }

        return json_encode($data);
    }

    public function deserialize(string $serialized): Event
    {
        $data = json_decode($serialized, true);
        $eventClass = $data['type'];
        
        return new $eventClass(
            $data['data'],
            $data['metadata']['timestamp']
        );
    }
}

class StreamManager
{
    private array $streams = [];
    private StreamStore $store;

    public function __construct(StreamStore $store)
    {
        $this->store = $store;
    }

    public function getStream(string $streamId): EventStream
    {
        if (!isset($this->streams[$streamId])) {
            $this->streams[$streamId] = $this->store->load($streamId);
        }

        return $this->streams[$streamId];
    }

    public function createStream(string $streamId): EventStream
    {
        if (isset($this->streams[$streamId])) {
            throw new \RuntimeException("Stream {$streamId} already exists");
        }

        $stream = new EventStream($streamId);
        $this->streams[$streamId] = $stream;
        $this->store->save($stream);

        return $stream;
    }
}

class EventStream implements \IteratorAggregate
{
    private string $id;
    private array $events = [];
    private int $version = 0;

    public function __construct(string $id, array $events = [])
    {
        $this->id = $id;
        $this->events = $events;
    }

    public function append(Event $event): void
    {
        $this->events[] = $event;
        $this->version++;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getNextSequence(): int
    {
        return count($this->events) + 1;
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->events);
    }
}

interface StorageAdapter
{
    public function append(EventStream $stream, array $eventData): void;
    public function read(string $streamId, int $fromSequence = 0): array;
}

class DatabaseStorageAdapter implements StorageAdapter
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function append(EventStream $stream, array $eventData): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO event_store (stream_id, sequence, event_type, payload, metadata, created_at) 
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $eventData['metadata']['stream_id'],
            $eventData['metadata']['sequence'],
            $eventData['event_type'],
            $eventData['payload'],
            json_encode($eventData['metadata']),
            date('Y-m-d H:i:s')
        ]);
    }

    public function read(string $streamId, int $fromSequence = 0): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM event_store 
             WHERE stream_id = ? AND sequence > ? 
             ORDER BY sequence ASC'
        );

        $stmt->execute([$streamId, $fromSequence]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}

class RedisStorageAdapter implements StorageAdapter
{
    private \Redis $redis;
    private string $prefix;

    public function __construct(\Redis $redis, string $prefix = 'event_store:')
    {
        $this->redis = $redis;
        $this->prefix = $prefix;
    }

    public function append(EventStream $stream, array $eventData): void
    {
        $key = $this->prefix . $eventData['metadata']['stream_id'];
        $this->redis->rPush($key, json_encode($eventData));
    }

    public function read(string $streamId, int $fromSequence = 0): array
    {
        $key = $this->prefix . $streamId;
        $events = $this->redis->lRange($key, $fromSequence, -1);
        
        return array_map(
            fn($event) => json_decode($event, true),
            $events
        );
    }
}

class StreamStore
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function load(string $streamId): EventStream
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM event_streams WHERE id = ?'
        );
        $stmt->execute([$streamId]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$data) {
            throw new \RuntimeException("Stream {$streamId} not found");
        }

        return new EventStream(
            $streamId,
            json_decode($data['events'], true)
        );
    }

    public function save(EventStream $stream): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO event_streams (id, version, events, updated_at) 
             VALUES (?, ?, ?, ?) 
             ON DUPLICATE KEY UPDATE 
             version = VALUES(version), 
             events = VALUES(events), 
             updated_at = VALUES(updated_at)'
        );

        $stmt->execute([
            $stream->getId(),
            $stream->getVersion(),
            json_encode(iterator_to_array($stream)),
            date('Y-m-d H:i:s')
        ]);
    }
}
