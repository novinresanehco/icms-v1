<?php

namespace App\Core\Events;

class EventManager
{
    private EventDispatcher $dispatcher;
    private EventStore $store;
    private EventValidator $validator;
    private EventLogger $logger;
    private RetryManager $retryManager;

    public function __construct(
        EventDispatcher $dispatcher,
        EventStore $store,
        EventValidator $validator,
        EventLogger $logger,
        RetryManager $retryManager
    ) {
        $this->dispatcher = $dispatcher;
        $this->store = $store;
        $this->validator = $validator;
        $this->logger = $logger;
        $this->retryManager = $retryManager;
    }

    public function dispatch(Event $event): EventResult
    {
        if (!$this->validator->validate($event)) {
            throw new InvalidEventException("Invalid event: {$event->getName()}");
        }

        try {
            $this->store->store($event);
            $result = $this->dispatcher->dispatch($event);
            
            if (!$result->isSuccess() && $this->retryManager->shouldRetry($event)) {
                $this->scheduleRetry($event);
            }

            $this->logger->logEventDispatched($event, $result);
            return $result;

        } catch (\Exception $e) {
            $this->handleDispatchError($event, $e);
            throw $e;
        }
    }

    public function subscribe(string $eventName, callable $handler, array $options = []): string
    {
        $subscriptionId = $this->generateSubscriptionId();
        $this->dispatcher->subscribe($eventName, $handler, $subscriptionId, $options);
        return $subscriptionId;
    }

    public function unsubscribe(string $subscriptionId): void
    {
        $this->dispatcher->unsubscribe($subscriptionId);
    }

    public function getEventHistory(array $filters = []): array
    {
        return $this->store->getEvents($filters);
    }

    public function replayEvents(array $eventIds): ReplayResult
    {
        $result = new ReplayResult();

        foreach ($eventIds as $eventId) {
            try {
                $event = $this->store->getEvent($eventId);
                $dispatchResult = $this->dispatcher->dispatch($event);
                $result->addResult($eventId, $dispatchResult);
            } catch (\Exception $e) {
                $result->addError($eventId, $e);
            }
        }

        return $result;
    }

    protected function scheduleRetry(Event $event): void
    {
        $delay = $this->retryManager->getNextRetryDelay($event);
        $this->dispatcher->scheduleRetry($event, $delay);
        $this->logger->logRetryScheduled($event, $delay);
    }

    protected function handleDispatchError(Event $event, \Exception $e): void
    {
        $this->logger->logEventError($event, $e);
        
        if ($this->retryManager->shouldRetry($event)) {
            $this->scheduleRetry($event);
        }
    }

    protected function generateSubscriptionId(): string
    {
        return uniqid('sub_', true);
    }
}

class EventDispatcher
{
    private array $subscribers = [];
    private EventQueue $queue;
    private MetricsCollector $metrics;

    public function dispatch(Event $event): EventResult
    {
        $startTime = microtime(true);
        $results = [];

        $subscribers = $this->getSubscribers($event->getName());

        foreach ($subscribers as $subscriber) {
            try {
                $result = $subscriber['handler']($event);
                $results[] = ['success' => true, 'result' => $result];
            } catch (\Exception $e) {
                $results[] = ['success' => false, 'error' => $e->getMessage()];
            }
        }

        $this->metrics->recordDispatch($event, microtime(true) - $startTime);
        return new EventResult($results);
    }

    public function subscribe(string $eventName, callable $handler, string $id, array $options): void
    {
        $this->subscribers[$eventName][$id] = [
            'handler' => $handler,
            'options' => $options
        ];
    }

    public function unsubscribe(string $id): void
    {
        foreach ($this->subscribers as $eventName => $subscribers) {
            if (isset($subscribers[$id])) {
                unset($this->subscribers[$eventName][$id]);
            }
        }
    }

    public function scheduleRetry(Event $event, int $delay): void
    {
        $this->queue->enqueue($event, time() + $delay);
    }

    protected function getSubscribers(string $eventName): array
    {
        return $this->subscribers[$eventName] ?? [];
    }
}

class EventStore
{
    private DatabaseConnection $db;
    private Serializer $serializer;
    private array $config;

    public function store(Event $event): void
    {
        $serialized = $this->serializer->serialize($event);
        
        $this->db->insert('events', [
            'name' => $event->getName(),
            'data' => $serialized,
            'created_at' => time()
        ]);
    }

    public function getEvent(string $eventId): Event
    {
        $data = $this->db->fetch('events', ['id' => $eventId]);
        
        if (!$data) {
            throw new EventNotFoundException($eventId);
        }
        
        return $this->serializer->deserialize($data['data']);
    }

    public function getEvents(array $filters = []): array
    {
        $query = $this->buildQuery($filters);
        return array_map(
            fn($data) => $this->serializer->deserialize($data['data']),
            $this->db->fetchAll($query)
        );
    }

    protected function buildQuery(array $filters): array
    {
        $query = ['table' => 'events'];
        
        if (isset($filters['name'])) {
            $query['where']['name'] = $filters['name'];
        }
        
        if (isset($filters['from'])) {
            $query['where']['created_at >= ?'] = $filters['from'];
        }
        
        if (isset($filters['to'])) {
            $query['where']['created_at <= ?'] = $filters['to'];
        }
        
        return $query;
    }
}

interface Event
{
    public function getName(): string;
    public function getPayload(): array;
    public function getMetadata(): array;
    public function getTimestamp(): int;
}

class EventResult
{
    private array $results;

    public function __construct(array $results)
    {
        $this->results = $results;
    }

    public function isSuccess(): bool
    {
        return !empty($this->results) && 
               array_reduce($this->results, fn($carry, $result) => $carry && $result['success'], true);
    }

    public function getResults(): array
    {
        return $this->results;
    }

    public function getErrors(): array
    {
        return array_filter($this->results, fn($result) => !$result['success']);
    }
}

class EventLogger
{
    private LoggerInterface $logger;

    public function logEventDispatched(Event $event, EventResult $result): void
    {
        $this->logger->info('Event dispatched', [
            'event' => $event->getName(),
            'success' => $result->isSuccess(),
            'timestamp' => time()
        ]);
    }

    public function logEventError(Event $event, \Exception $e): void
    {
        $this->logger->error('Event dispatch error', [
            'event' => $event->getName(),
            'error' => $e->getMessage(),
            'timestamp' => time()
        ]);
    }

    public function logRetryScheduled(Event $event, int $delay): void
    {
        $this->logger->info('Event retry scheduled', [
            'event' => $event->getName(),
            'delay' => $delay,
            'timestamp' => time()
        ]);
    }
}
