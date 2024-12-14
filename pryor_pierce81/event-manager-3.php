```php
<?php

namespace App\Core\Events;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\EventException;
use Psr\Log\LoggerInterface;

class EventManager implements EventManagerInterface 
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $listeners = [];
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function dispatch(string $event, array $payload = []): void 
    {
        $eventId = $this->generateEventId();

        try {
            DB::beginTransaction();

            $this->security->validateContext('event:dispatch');
            $this->validateEvent($event);
            $this->validatePayload($payload);

            $this->logEventDispatch($eventId, $event, $payload);

            foreach ($this->getListeners($event) as $listener) {
                $this->executeListener($eventId, $event, $listener, $payload);
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleEventFailure($eventId, $event, $payload, $e);
            throw new EventException("Event dispatch failed: {$event}", 0, $e);
        }
    }

    public function listen(string $event, callable $listener): void 
    {
        try {
            $this->security->validateContext('event:listen');
            $this->validateEvent($event);
            $this->validateListener($listener);

            $this->listeners[$event][] = [
                'callback' => $listener,
                'registered_at' => microtime(true)
            ];

            $this->logListenerRegistration($event);

        } catch (\Exception $e) {
            $this->handleListenerRegistrationFailure($event, $e);
            throw new EventException("Listener registration failed: {$event}", 0, $e);
        }
    }

    private function executeListener(
        string $eventId,
        string $event,
        array $listener,
        array $payload
    ): void {
        try {
            $startTime = microtime(true);
            
            $result = ($listener['callback'])($payload);
            
            $duration = microtime(true) - $startTime;
            
            if ($duration > $this->config['max_listener_time']) {
                throw new EventException("Listener execution timeout");
            }

            $this->logListenerExecution($eventId, $event, $duration);

        } catch (\Exception $e) {
            if (!$this->config['continue_on_error']) {
                throw $e;
            }
            $this->logListenerFailure($eventId, $event, $e);
        }
    }

    private function validateEvent(string $event): void 
    {
        if (empty($event)) {
            throw new EventException("Event name cannot be empty");
        }

        if (!preg_match('/^[a-zA-Z0-9\.:_-]+$/', $event)) {
            throw new EventException("Invalid event name format");
        }
    }

    private function validatePayload(array $payload): void 
    {
        $size = strlen(serialize($payload));
        
        if ($size > $this->config['max_payload_size']) {
            throw new EventException("Event payload exceeds maximum size");
        }
    }

    private function validateListener(callable $listener): void 
    {
        if (!is_callable($listener)) {
            throw new EventException("Invalid listener format");
        }
    }

    private function getListeners(string $event): array 
    {
        return array_merge(
            $this->listeners[$event] ?? [],
            $this->listeners['*'] ?? []
        );
    }

    private function generateEventId(): string 
    {
        return uniqid('evt_', true);
    }

    private function logEventDispatch(
        string $eventId,
        string $event,
        array $payload
    ): void {
        $this->logger->info('Event dispatched', [
            'event_id' => $eventId,
            'event' => $event,
            'payload_size' => strlen(serialize($payload)),
            'timestamp' => microtime(true)
        ]);
    }

    private function logListenerRegistration(string $event): void 
    {
        $this->logger->info('Listener registered', [
            'event' => $event,
            'timestamp' => microtime(true)
        ]);
    }

    private function logListenerExecution(
        string $eventId,
        string $event,
        float $duration
    ): void {
        $this->logger->info('Listener executed', [
            'event_id' => $eventId,
            'event' => $event,
            'duration' => $duration,
            'timestamp' => microtime(true)
        ]);
    }

    private function logListenerFailure(
        string $eventId,
        string $event,
        \Exception $e
    ): void {
        $this->logger->error('Listener execution failed', [
            'event_id' => $eventId,
            'event' => $event,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function handleEventFailure(
        string $eventId,
        string $event,
        array $payload,
        \Exception $e
    ): void {
        $this->logger->error('Event dispatch failed', [
            'event_id' => $eventId,
            'event' => $event,
            'payload_size' => strlen(serialize($payload)),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function handleListenerRegistrationFailure(
        string $event,
        \Exception $e
    ): void {
        $this->logger->error('Listener registration failed', [
            'event' => $event,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function getDefaultConfig(): array 
    {
        return [
            'max_payload_size' => 65536, // 64KB
            'max_listener_time' => 30,    // seconds
            'continue_on_error' => false,
            'async_dispatch' => false,
            'retry_attempts' => 3
        ];
    }
}
```
