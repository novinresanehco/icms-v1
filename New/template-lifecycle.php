<?php

namespace App\Core\Template\Lifecycle;

use App\Core\Template\Exceptions\LifecycleException;

interface LifecycleEventInterface
{
    public function getName(): string;
    public function getPayload(): array;
}

class LifecycleEvent implements LifecycleEventInterface
{
    private string $name;
    private array $payload;
    private float $timestamp;

    public function __construct(string $name, array $payload = [])
    {
        $this->name = $name;
        $this->payload = $payload;
        $this->timestamp = microtime(true);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getTimestamp(): float
    {
        return $this->timestamp;
    }
}

interface LifecycleListenerInterface
{
    public function handleEvent(LifecycleEventInterface $event): void;
    public function getSubscribedEvents(): array;
}

class LifecycleManager
{
    private array $listeners = [];
    private array $eventLog = [];
    
    public function addEventListener(LifecycleListenerInterface $listener): void
    {
        foreach ($listener->getSubscribedEvents() as $eventName) {
            $this->listeners[$eventName][] = $listener;
        }
    }

    public function dispatchEvent(LifecycleEventInterface $event): void
    {
        $this->logEvent($event);
        
        if (!isset($this->listeners[$event->getName()])) {
            return;
        }

        foreach ($this->listeners[$event->getName()] as $listener) {
            try {
                $listener->handleEvent($event);
            } catch (\Throwable $e) {
                throw new LifecycleException(
                    "Error in event listener: " . $e->getMessage(),
                    0,
                    $e
                );
            }
        }
    }

    private function logEvent(LifecycleEventInterface $event): void
    {
        $this->eventLog[] = [
            'name' => $event->getName(),
            'payload' => $event->getPayload(),
            'timestamp' => $event->getTimestamp()
        ];
    }

    public function getEventLog(): array
    {
        return $this->eventLog;
    }
}

abstract class BaseLifecycleListener implements LifecycleListenerInterface
{
    protected TemplateMonitoringService $monitor;

    public function __construct(TemplateMonitoringService $monitor)
    {
        $this->monitor = $monitor;
    }

    abstract public function handleEvent(LifecycleEventInterface $event): void;
    abstract public function getSubscribedEvents(): array;
}

class CompilationListener extends BaseLifecycleListener
{
    public function getSubscribedEvents(): array
    {
        return [
            'template.compilation.start',
            'template.compilation.end',
            'template.compilation.error'
        ];
    }

    public function handleEvent(LifecycleEventInterface $event): void
    {
        match($event->getName()) {
            'template.compilation.start' => $this->handleCompilationStart($event),
            'template.compilation.end' => $this->handleCompilationEnd($event),
            'template.compilation.error' => $this->handleCompilationError($event),
            default => null
        };
    }

    private function handleCompilationStart(LifecycleEventInterface $event): void
    {
        $this->monitor->recordMetric('template.compilation.started', 1, [
            'template' => $event->getPayload()['template'] ?? 'unknown'
        ]);
    }

    private function handleCompilationEnd(LifecycleEventInterface $event): void
    {
        $this->monitor->recordMetric('template.compilation.completed', 1, [
            'template' => $event->getPayload()['template'] ?? 'unknown',
            'duration' => $event->getPayload()['duration'] ?? 0
        ]);
    }

    private function handleCompilationError(LifecycleEventInterface $event): void
    {
        $this->monitor->recordError(
            $event->getPayload()['message'] ?? 'Unknown compilation error',
            'error',
            $event->getPayload()
        );
    }
}

class ValidationListener extends BaseLifecycleListener
{
    public function getSubscribedEvents(): array
    {
        return [
            'template.validation.start',
            'template.validation.end',
            'template.validation.error'
        ];
    }

    public function handleEvent(LifecycleEventInterface $event): void
    {
        match($event->getName()) {
            'template.validation.start' => $this->handleValidationStart($event),
            'template.validation.end' => $this->handleValidationEnd($event),
            'template.validation.error' => $this->handleValidationError($event),
            default => null
        };
    }

    private function handleValidationStart(LifecycleEventInterface $event): void
    {
        $this->monitor->recordMetric('template.validation.started', 1, [
            'template' => $event->getPayload()['template'] ?? 'unknown'
        ]);
    }

    private function handleValidationEnd(LifecycleEventInterface $event): void
    {
        $this->monitor->recordMetric('template.validation.completed', 1, [
            'template' => $event->getPayload()['template'] ?? 'unknown',
            'duration' => $event->getPayload()['duration'] ?? 0,
            'rules_validated' => $event->getPayload()['