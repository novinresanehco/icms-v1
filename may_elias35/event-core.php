<?php

namespace App\Core\Events;

class EventManager implements EventInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private ProcessingQueue $queue;
    private ListenerRegistry $listeners;
    private AuditLogger $logger;
    private MetricsCollector $metrics;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        ProcessingQueue $queue,
        ListenerRegistry $listeners,
        AuditLogger $logger,
        MetricsCollector $metrics
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->queue = $queue;
        $this->listeners = $listeners;
        $this->logger = $logger;
        $this->metrics = $metrics;
    }

    public function dispatch(string $event, array $payload): void
    {
        $eventId = uniqid('evt_', true);
        
        try {
            $this->validateEvent($event, $payload);
            $this->security->validateEventContext();

            $securePayload = $this->securePayload($payload);
            $this->recordEvent($eventId, $event, $securePayload);

            $listeners = $this->getEventListeners($event);
            $this->processEventListeners($eventId, $event, $securePayload, $listeners);
            
        } catch (\Exception $e) {
            $this->handleEventFailure($eventId, $event, $e);
            throw new EventException('Event dispatch failed', 0, $e);
        }
    }

    private function validateEvent(string $event, array $payload): void
    {
        if (!$this->validator->validateEventType($event)) {
            throw new ValidationException('Invalid event type');
        }

        if (!$this->validator->validateEventPayload($payload)) {
            throw new ValidationException('Invalid event payload');
        }

        if ($this->security->isRestrictedEvent($event)) {
            throw new SecurityException('Restricted event type');
        }
    }

    private function securePayload(array $payload): array
    {
        $sensitiveFields = $this->security->getSensitiveFields();
        $securedPayload = [];

        foreach ($payload as $key => $value) {
            if (in_array($key, $sensitiveFields)) {
                $securedPayload[$key] = $this->security->encryptField($value);
            } else {
                $securedPayload[$key] = $value;
            }
        }

        $securedPayload['_hash'] = $this->generatePayloadHash($securedPayload);
        return $securedPayload;
    }

    private function recordEvent(string $eventId, string $event, array $payload): void
    {
        $this->logger->logEvent([
            'event_id' => $eventId,
            'type' => $event,
            'payload_hash' => $payload['_hash'],
            'timestamp' => now(),
            'context' => $this->security->getContext()
        ]);

        $this->metrics->recordEvent($event, [
            'timestamp' => microtime(true),
            'payload_size' => strlen(json_encode($payload))
        ]);
    }

    private function getEventListeners(string $event): array
    {
        $listeners = $this->listeners->getListeners($event);
        
        foreach ($listeners as $listener) {
            if (!$this->validateListener($listener)) {
                throw new ListenerException('Invalid event listener');
            }
        }

        return $listeners;
    }

    private function processEventListeners(
        string $eventId,
        string $event,
        array $payload,
        array $listeners
    ): void {
        foreach ($listeners as $listener) {
            try {
                $this->queue->push(new EventJob([
                    'event_id' => $eventId,
                    'event' => $event,
                    'payload' => $payload,
                    'listener' => $listener
                ]));

            } catch (\Exception $e) {
                $this->handleListenerFailure($eventId, $event, $listener, $e);
            }
        }
    }

    private function validateListener($listener): bool
    {
        if (!$listener instanceof EventListenerInterface) {
            return false;
        }

        if (!$this->security->validateListener($listener)) {
            return false;
        }

        return true;
    }

    private function handleEventFailure(string $eventId, string $event, \Exception $e): void
    {
        $this->logger->logEventFailure([
            'event_id' => $eventId,
            'type' => $event,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'timestamp' => now()
        ]);

        if ($e instanceof SecurityException) {
            $this->security->handleSecurityIncident($eventId, $e);
        }

        $this->metrics->recordFailure($event, [
            'timestamp' => microtime(true),
            'error_type' => get_class($e)
        ]);
    }

    private function handleListenerFailure(
        string $eventId,
        string $event,
        $listener,
        \Exception $e
    ): void {
        $this->logger->logListenerFailure([
            'event_id' => $eventId,
            'type' => $event,
            'listener' => get_class($listener),
            'error' => $e->getMessage(),
            'timestamp' => now()
        ]);

        $this->metrics->recordListenerFailure($event, [
            'listener' => get_class($listener),
            'timestamp' => microtime(true)
        ]);
    }

    private function generatePayloadHash(array $payload): string
    {
        return hash_hmac(
            'sha256',
            json_encode($payload),
            $this->security->getSecretKey()
        );
    }
}
