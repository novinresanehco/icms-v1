<?php

namespace App\Core\Events;

class EventDispatcher
{
    private array $listeners = [];
    private array $wildcardListeners = [];
    private EventStore $eventStore;
    private bool $isRecording = false;
    private array $recordedEvents = [];

    public function dispatch(Event $event): void
    {
        $eventName = get_class($event);
        
        if ($this->isRecording) {
            $this->recordedEvents[] = $event;
        }

        $this->eventStore->store($event);

        foreach ($this->getListeners($eventName) as $listener) {
            try {
                $listener->handle($event);
            } catch (\Exception $e) {
                $this->handleListenerFailure($e, $event, $listener);
            }
        }
    }

    public function addListener(string $eventName, EventListener $listener): void
    {
        if (str_contains($eventName, '*')) {
            $this->wildcardListeners[$eventName][] = $listener;
        } else {
            $this->listeners[$eventName][] = $listener;
        }
    }

    public function removeListener(string $eventName, EventListener $listener): void
    {
        if (isset($this->listeners[$eventName])) {
            $this->listeners[$eventName] = array_filter(
                $this->listeners[$eventName],
                fn($l) => $l !== $listener
            );
        }
    }

    public function startRecording(): void
    {
        $this->isRecording = true;
    }

    public function stopRecording(): array
    {
        $this->isRecording = false;
        $events = $this->recordedEvents;
        $this->recordedEvents = [];
        return $events;
    }

    private function getListeners(string $eventName): array
    {
        $listeners = $this->listeners[$eventName] ?? [];

        foreach ($this->wildcardListeners as $pattern => $wildcardListeners) {
            if (fnmatch($pattern, $eventName)) {
                $listeners = array_merge($listeners, $wildcardListeners);
            }
        }

        return $listeners;
    }

    private function handleListenerFailure(\Exception $e, Event $event, EventListener $listener): void
    {
        $this->eventStore->recordFailure($event, $listener, $e);
        
        if (!$listener->shouldFailSilently()) {
            throw $e;
        }
    }
}

abstract class Event
{
    protected string $id;
    protected int $timestamp;
    protected array $metadata;

    public function __construct(array $metadata = [])
    {
        $this->id = uniqid('event_', true);
        $this->timestamp = time();
        $this->metadata = $metadata;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    abstract public function getName(): string;
    abstract public function getDescription(): string;
}

interface EventListener
{
    public function handle(Event $event): void;
    public function shouldFailSilently(): bool;
}

class EventStore
{
    private $connection;

    public function store(Event $event): void
    {
        $this->connection->table('events')->insert([
            'id' => $event->getId(),
            'type' => get_class($event),
            'data' => serialize($event),
            'metadata' => json_encode($event->getMetadata()),
            'created_at' => now()
        ]);
    }

    public function recordFailure(Event $event, EventListener $listener, \Exception $e): void
    {
        $this->connection->table('event_failures')->insert([
            'event_id' => $event->getId(),
            'listener' => get_class($listener),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'failed_at' => now()
        ]);
    }

    public function getEvents(array $filters = []): array
    {
        $query = $this->connection->table('events');

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (isset($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        return $query->get()
            ->map(fn($row) => unserialize($row->data))
            ->toArray();
    }
}

class AsyncEventDispatcher extends EventDispatcher
{
    private $queue;

    public function dispatch(Event $event): void
    {
        $this->queue->push(new HandleEventJob($event));
    }
}

class HandleEventJob
{
    private Event $event;

    public function __construct(Event $event)
    {
        $this->event = $event;
    }

    public function handle(EventDispatcher $dispatcher): void
    {
        $dispatcher->dispatch($this->event);
    }
}

class EventSubscriber
{
    protected array $subscribes = [];

    public function subscribe(EventDispatcher $dispatcher): void
    {
        foreach ($this->subscribes as $event => $method) {
            $dispatcher->addListener($event, new MethodListener($this, $method));
        }
    }
}

class MethodListener implements EventListener
{
    private $instance;
    private string $method;
    private bool $failSilently;

    public function __construct($instance, string $method, bool $failSilently = false)
    {
        $this->instance = $instance;
        $this->method = $method;
        $this->failSilently = $failSilently;
    }

    public function handle(Event $event): void
    {
        call_user_func([$this->instance, $this->method], $event);
    }

    public function shouldFailSilently(): bool
    {
        return $this->failSilently;
    }
}

class EventQuery
{
    private EventStore $store;
    private array $filters = [];

    public function ofType(string $type): self
    {
        $this->filters['type'] = $type;
        return $this;
    }

    public function from(\DateTime $from): self
    {
        $this->filters['from'] = $from;
        return $this;
    }

    public function to(\DateTime $to): self
    {
        $this->filters['to'] = $to;
        return $this;
    }

    public function get(): array
    {
        return $this->store->getEvents($this->filters);
    }
}

class EventReplay
{
    private EventDispatcher $dispatcher;
    private EventStore $store;

    public function replay(array $events): void
    {
        foreach ($events as $event) {
            $this->dispatcher->dispatch($event);
        }
    }

    public function replayFromDate(\DateTime $from, \DateTime $to = null): void
    {
        $query = new EventQuery($this->store);
        $query->from($from);
        
        if ($to) {
            $query->to($to);
        }

        $this->replay($query->get());
    }
}
