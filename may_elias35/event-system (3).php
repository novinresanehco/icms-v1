// File: app/Core/Event/Manager/EventManager.php
<?php

namespace App\Core\Event\Manager;

class EventManager
{
    protected ListenerRegistry $registry;
    protected EventDispatcher $dispatcher;
    protected EventStore $store;
    protected MetricsCollector $metrics;

    public function dispatch(Event $event): void
    {
        DB::beginTransaction();
        try {
            // Store event
            $this->store->store($event);
            
            // Get listeners
            $listeners = $this->registry->getListeners($event);
            
            // Dispatch to listeners
            foreach ($listeners as $listener) {
                $this->dispatcher->dispatch($event, $listener);
            }
            
            // Record metrics
            $this->metrics->recordDispatch($event, count($listeners));
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new EventException("Failed to dispatch event: " . $e->getMessage());
        }
    }

    public function subscribe(string $eventName, EventListener $listener): void
    {
        $this->registry->register($eventName, $listener);
    }

    public function unsubscribe(string $eventName, EventListener $listener): void
    {
        $this->registry->unregister($eventName, $listener);
    }
}

// File: app/Core/Event/Dispatcher/EventDispatcher.php
<?php

namespace App\Core\Event\Dispatcher;

class EventDispatcher
{
    protected QueueManager $queue;
    protected ListenerValidator $validator;
    protected ErrorHandler $errorHandler;

    public function dispatch(Event $event, EventListener $listener): void
    {
        try {
            // Validate listener
            $this->validator->validate($listener);
            
            if ($listener->isAsync()) {
                $this->dispatchAsync($event, $listener);
            } else {
                $this->dispatchSync($event, $listener);
            }
        } catch (\Exception $e) {
            $this->errorHandler->handle($e, $event, $listener);
        }
    }

    protected function dispatchAsync(Event $event, EventListener $listener): void
    {
        $this->queue->push(new ListenerJob($event, $listener));
    }

    protected function dispatchSync(Event $event, EventListener $listener): void
    {
        $listener->handle($event);
    }
}

// File: app/Core/Event/Store/EventStore.php
<?php

namespace App\Core\Event\Store;

class EventStore
{
    protected Repository $repository;
    protected Serializer $serializer;
    protected StoreConfig $config;

    public function store(Event $event): void
    {
        $serialized = $this->serializer->serialize($event);
        
        $this->repository->create([
            'name' => $event->getName(),
            'payload' => $serialized,
            'metadata' => $this->buildMetadata($event),
            'occurred_at' => now()
        ]);
    }

    public function getEvents(array $filters = []): array
    {
        $events = $this->repository->findBy($filters);
        
        return array_map(function($event) {
            return $this->serializer->unserialize($event->payload);
        }, $events);
    }

    protected function buildMetadata(Event $event): array
    {
        return [
            'user_id' => auth()->id(),
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'correlation_id' => $event->getCorrelationId()
        ];
    }
}

// File: app/Core/Event/Stream/EventStream.php
<?php

namespace App\Core\Event\Stream;

class EventStream
{
    protected EventStore $store;
    protected StreamProcessor $processor;
    protected StreamConfig $config;

    public function subscribe(string $stream, callable $callback): Subscription
    {
        $subscription = new Subscription($stream, $callback);
        
        $this->processor->process($stream, function($event) use ($callback) {
            $callback($event);
        });

        return $subscription;
    }

    public function publish(string $stream, Event $event): void
    {
        $this->store->store($event);
        $this->processor->notify($stream, $event);
    }

    public function replay(string $stream, DateTimeInterface $from): void
    {
        $events = $this->store->getEvents([
            'stream' => $stream,
            'from' => $from
        ]);

        foreach ($events as $event) {
            $this->processor->process($stream, $event);
        }
    }
}
