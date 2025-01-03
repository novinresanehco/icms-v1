<?php

namespace App\Core\Events;

class EventManager implements EventManagerInterface
{
    private array $listeners = [];
    private MonitoringService $monitor;
    private SecurityService $security;
    private LogManager $logger;

    public function __construct(
        MonitoringService $monitor,
        SecurityService $security,
        LogManager $logger
    ) {
        $this->monitor = $monitor;
        $this->security = $security;
        $this->logger = $logger;
    }

    public function dispatch(Event $event): void
    {
        $context = new EventContext($event);
        
        try {
            $this->monitor->startEvent($context);
            $this->validateEvent($event);

            foreach ($this->getListeners($event) as $listener) {
                $this->executeListener($listener, $event, $context);
            }

        } catch (\Exception $e) {
            $this->handleEventFailure($e, $context);
            throw $e;
        } finally {
            $this->monitor->endEvent($context);
        }
    }

    public function listen(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    protected function validateEvent(Event $event): void
    {
        if ($event instanceof SecurityEvent) {
            $this->security->validateEvent($event);
        }

        if (!$this->isValidEvent($event)) {
            throw new InvalidEventException('Invalid event structure');
        }
    }

    protected function executeListener(callable $listener, Event $event, EventContext $context): void
    {
        try {
            $listener($event);
            
        } catch (\Exception $e) {
            $this->handleListenerFailure($e, $listener, $context);
            
            if ($e instanceof CriticalException) {
                throw $e;
            }
        }
    }

    protected function handleEventFailure(\Exception $e, EventContext $context): void
    {
        $this->logger->error('Event execution failed', [
            'event' => $context->getEventName(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->monitor->trackEventFailure($context, $e);

        if ($e instanceof SecurityException) {
            $this->security->handleSecurityEvent('event_failure', $context);
        }
    }

    protected function handleListenerFailure(
        \Exception $e,
        callable $listener,
        EventContext $context
    ): void {
        $this->logger->warning('Event listener failed', [
            'event' => $context->getEventName(),
            'listener' => $this->getListenerName($listener),
            'error' => $e->getMessage()
        ]);

        $this->monitor->trackListenerFailure($context, $e);
    }

    private function getListeners(Event $event): array
    {
        return $this->listeners[get_class($event)] ?? [];
    }

    private function isValidEvent(Event $event): bool
    {
        return method_exists($event, 'getData') &&
               method_exists($event, 'getTimestamp');
    }

    private function getListenerName(callable $listener): string
    {
        if (is_array($listener)) {
            return is_object($listener[0]) 
                ? get_class($listener[0]) . '@' . $listener[1]
                : $listener[0] . '::' . $listener[1];
        }

        if ($listener instanceof \Closure) {
            return 'Closure';
        }

        return is_object($listener) ? get_class($listener) : strval($listener);
    }
}

abstract class Event
{
    protected array $data;
    protected float $timestamp;

    public function __construct(array $data = [])
    {
        $this->data = $data;
        $this->timestamp = microtime(true);
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }
}

class EventContext
{
    private Event $event;
    private float $startTime;
    private array $metadata;

    public function __construct(Event $event)
    {
        $this->event = $event;
        $this->startTime = microtime(true);
        $this->metadata = [
            'event_class' => get_class($event),
            'dispatch_time' => $this->startTime
        ];
    }

    public function getEventName(): string
    {
        return $this->metadata['event_class'];
    }

    public function getDuration(): float
    {
        return microtime(true) - $this->startTime;
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}

interface EventManagerInterface
{
    public function dispatch(Event $event): void;
    public function listen(string $event, callable $listener): void;
}