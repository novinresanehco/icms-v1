<?php

namespace App\Core\Events;

class EventDispatcher
{
    private array $listeners = [];
    private ListenerRegistry $registry;
    private EventProcessor $processor;
    private EventLogger $logger;

    public function __construct(
        ListenerRegistry $registry,
        EventProcessor $processor,
        EventLogger $logger
    ) {
        $this->registry = $registry;
        $this->processor = $processor;
        $this->logger = $logger;
    }

    public function dispatch(Event $event): void
    {
        $this->logger->logEvent($event);
        $listeners = $this->registry->getListeners($event);

        foreach ($listeners as $listener) {
            try {
                $this->processor->process($event, $listener);
            } catch (\Exception $e) {
                $this->handleError($e, $event, $listener);
            }
        }
    }

    private function handleError(\Exception $e, Event $event, EventListener $listener): void
    {
        $this->logger->logError($e, [
            'event' => get_class($event),
            'listener' => get_class($listener),
            'error' => $e->getMessage()
        ]);
    }
}

class ListenerRegistry
{
    private array $listeners = [];

    public function register(string $eventName, EventListener $listener, int $priority = 0): void
    {
        $this->listeners[$eventName][$priority][] = $listener;
        ksort($this->listeners[$eventName]);
    }

    public function getListeners(Event $event): array
    {
        $eventName = get_class($event);
        return $this->listeners[$eventName] ?? [];
    }

    public function clearListeners(string $eventName): void
    {
        unset($this->listeners[$eventName]);
    }
}

interface Event
{
    public function getName(): string;
    public function getTime(): int;
    public function getData(): array;
}

interface EventListener
{
    public function handle(Event $event): void;
}

class EventProcessor
{
    private EventValidator $validator;
    private ProcessingMetrics $metrics;

    public function __construct(EventValidator $validator, ProcessingMetrics $metrics)
    {
        $this->validator = $validator;
        $this->metrics = $metrics;
    }

    public function process(Event $event, EventListener $listener): void
    {
        $startTime = microtime(true);

        try {
            $this->validator->validate($event);
            $listener->handle($event);
            
            $this->metrics->recordSuccess(
                $event,
                $listener,
                microtime(true) - $startTime
            );
        } catch (\Exception $e) {
            $this->metrics->recordFailure($event, $listener);
            throw $e;
        }
    }
}

class EventValidator
{
    public function validate(Event $event): void
    {
        if (empty($event->getName())) {
            throw new EventValidationException('Event name is required');
        }

        if (empty($event->getData())) {
            throw new EventValidationException('Event data is required');
        }

        if ($event->getTime() <= 0) {
            throw new EventValidationException('Invalid event time');
        }
    }
}
