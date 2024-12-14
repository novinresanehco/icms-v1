<?php

namespace App\Core\Events;

use App\Core\Security\SecurityContext;
use App\Core\Monitoring\SystemMonitor;
use Illuminate\Support\Facades\Event;

class EventManager implements EventInterface
{
    private SecurityContext $security;
    private SystemMonitor $monitor;
    private array $config;
    private array $listeners = [];

    public function __construct(
        SecurityContext $security,
        SystemMonitor $monitor,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->config = $config;
        $this->initializeListeners();
    }

    public function dispatchCriticalEvent(string $event, array $data): void
    {
        $monitoringId = $this->monitor->startOperation('critical_event');
        
        try {
            $eventData = $this->prepareCriticalEvent($event, $data);
            
            $this->validateEvent($eventData);
            
            $this->dispatchEvent($event, $eventData);
            
            $this->monitor->recordSuccess($monitoringId);
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            $this->handleEventFailure($event, $data, $e);
            throw new EventException('Critical event dispatch failed', 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    public function dispatchSecurityEvent(string $event, array $data): void
    {
        $monitoringId = $this->monitor->startOperation('security_event');
        
        try {
            $eventData = $this->prepareSecurityEvent($event, $data);
            
            $this->validateEvent($eventData);
            
            $this->dispatchHighPriorityEvent($event, $eventData);
            
            $this->monitor->recordSuccess($monitoringId);
            
        } catch (\Exception $e) {
            $this->monitor->recordFailure($monitoringId, $e);
            $this->handleEventFailure($event, $data, $e);
            throw new EventException('Security event dispatch failed', 0, $e);
        } finally {
            $this->monitor->endOperation($monitoringId);
        }
    }

    private function prepareCriticalEvent(string $event, array $data): array
    {
        return [
            'type' => 'critical',
            'event' => $event,
            'data' => $this->sanitizeEventData($data),
            'context' => [
                'timestamp' => microtime(true),
                'system_state' => $this->monitor->captureSystemState()
            ],
            'security_context' => $this->security->getContext()
        ];
    }

    private function prepareSecurityEvent(string $event, array $data): array
    {
        return [
            'type' => 'security',
            'event' => $event,
            'data' => $this->sanitizeEventData($data),
            'context' => [
                'timestamp' => microtime(true),
                'security_context' => $this->security->getContext(),
                'priority' => 'high'
            ]
        ];
    }

    private function dispatchEvent(string $event, array $data): void
    {
        Event::dispatch($event, $data);
        
        foreach ($this->listeners[$event] ?? [] as $listener) {
            try {
                $listener->handle($data);
            } catch (\Exception $e) {
                $this->monitor->recordListenerFailure($listener, $e);
            }
        }
    }

    private function dispatchHighPriorityEvent(string $event, array $data): void
    {
        Event::dispatch("high_priority.{$event}", $data);
        
        foreach ($this->listeners[$event] ?? [] as $listener) {
            try {
                if ($listener instanceof HighPriorityListener) {
                    $listener->handleImmediate($data);
                } else {
                    $listener->handle($data);
                }
            } catch (\Exception $e) {
                $this->monitor->recordListenerFailure($listener, $e);
                $this->handleListenerFailure($event, $data, $listener, $e);
            }
        }
    }

    private function validateEvent(array $eventData): void
    {
        if (!isset($eventData['event']) || !isset($eventData['data'])) {
            throw new EventValidationException('Invalid event structure');
        }

        if (!$this->validateEventType($eventData['type'])) {
            throw new EventValidationException('Invalid event type');
        }
    }

    private function handleListenerFailure(
        string $event,
        array $data,
        $listener,
        \Exception $e
    ): void {
        $this->monitor->recordError('listener_failure', [
            'event' => $event,
            'listener' => get_class($listener),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
