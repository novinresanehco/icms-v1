<?php

namespace App\Core\Services;

use App\Core\Security\AuditService;
use Illuminate\Support\Facades\{Event, Queue};
use Illuminate\Contracts\Events\Dispatcher;

class EventService
{
    protected Dispatcher $eventDispatcher;
    protected AuditService $auditService;
    protected array $criticalEvents = [
        'security.breach',
        'system.failure',
        'data.corruption'
    ];

    public function __construct(
        Dispatcher $eventDispatcher,
        AuditService $auditService
    ) {
        $this->eventDispatcher = $eventDispatcher;
        $this->auditService = $auditService;
    }

    public function dispatch(string $event, array $payload = []): void
    {
        try {
            if ($this->isCriticalEvent($event)) {
                $this->handleCriticalEvent($event, $payload);
            }

            $this->eventDispatcher->dispatch($event, $payload);

            $this->auditService->logSecurityEvent('event_dispatched', [
                'event' => $event,
                'payload' => $this->sanitizePayload($payload)
            ]);

        } catch (\Exception $e) {
            $this->handleEventError($event, $e, $payload);
        }
    }

    public function dispatchAsync(string $event, array $payload = []): void
    {
        try {
            if ($this->isCriticalEvent($event)) {
                throw new \Exception('Critical events cannot be dispatched asynchronously');
            }

            Queue::push(function() use ($event, $payload) {
                $this->dispatch($event, $payload);
            });

        } catch (\Exception $e) {
            $this->handleEventError($event, $e, $payload);
        }
    }

    public function listen(string $event, callable $listener): void
    {
        $this->eventDispatcher->listen($event, function(...$args) use ($event, $listener) {
            try {
                $result = $listener(...$args);

                $this->auditService->logSecurityEvent('event_handled', [
                    'event' => $event,
                    'success' => true
                ]);

                return $result;

            } catch (\Exception $e) {
                $this->handleListenerError($event, $e);
                throw $e;
            }
        });
    }

    public function subscribe(string $subscriber): void
    {
        $this->eventDispatcher->subscribe($subscriber);
    }

    protected function handleCriticalEvent(string $event, array $payload): void
    {
        // Log critical event