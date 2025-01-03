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
        $this->auditService->logSecurityEvent('critical_event_occurred', [
            'event' => $event,
            'payload' => $this->sanitizePayload($payload),
            'timestamp' => now()
        ]);

        // Notify administrators
        $this->notifyAdministrators($event, $payload);

        // Enable additional monitoring
        $this->enableEnhancedMonitoring();
    }

    protected function handleEventError(string $event, \Exception $e, array $payload): void
    {
        $this->auditService->logSecurityEvent('event_dispatch_failed', [
            'event' => $event,
            'error' => $e->getMessage(),
            'payload' => $this->sanitizePayload($payload)
        ]);

        if ($this->isCriticalEvent($event)) {
            $this->handleCriticalFailure($event, $e);
        }

        throw $e;
    }

    protected function handleListenerError(string $event, \Exception $e): void
    {
        $this->auditService->logSecurityEvent('event_listener_failed', [
            'event' => $event,
            'error' => $e->getMessage()
        ]);
    }

    protected function handleCriticalFailure(string $event, \Exception $e): void
    {
        // Execute emergency protocols
        $this->executeCriticalFailureProtocols();
        
        // Enable system-wide monitoring
        $this->enableSystemWideMonitoring();
        
        // Create incident report
        $this->createIncidentReport($event, $e);
    }

    protected function isCriticalEvent(string $event): bool
    {
        return in_array($event, $this->criticalEvents);
    }

    protected function sanitizePayload(array $payload): array
    {
        // Remove sensitive data before logging
        $sanitized = [];
        foreach ($payload as $key => $value) {
            if ($this->isSensitiveKey($key)) {
                $sanitized[$key] = '[REDACTED]';
            } else {
                $sanitized[$key] = $value;
            }
        }
        return $sanitized;
    }

    protected function isSensitiveKey(string $key): bool
    {
        $sensitiveKeys = [
            'password',
            'token',
            'secret',
            'key',
            'auth',
            'credential'
        ];

        foreach ($sensitiveKeys as $sensitive) {
            if (str_contains(strtolower($key), $sensitive)) {
                return true;
            }
        }

        return false;
    }

    protected function notifyAdministrators(string $event, array $payload): void
    {
        // Implementation depends on notification system
    }

    protected function enableEnhancedMonitoring(): void
    {
        // Implementation depends on monitoring system
    }

    protected function enableSystemWideMonitoring(): void
    {
        // Implementation depends on monitoring system
    }

    protected function executeCriticalFailureProtocols(): void
    {
        // Implementation depends on system requirements
    }

    protected function createIncidentReport(string $event, \Exception $e): void
    {
        // Implementation depends on reporting system
    }
}