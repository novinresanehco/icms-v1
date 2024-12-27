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
    private TemplateMonitoringService $monitor;

    public function __construct(TemplateMonitoringService $monitor)
    {
        $this->monitor = $monitor;
    }

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
                $startTime = microtime(true);
                $listener->handleEvent($event);
                $duration = microtime(true) - $startTime;

                $this->monitor->recordMetric('lifecycle.listener.execution', $duration, [
                    'event' => $event->getName(),
                    'listener' => get_class($listener)
                ]);
            } catch (\Throwable $e) {
                $this->monitor->recordError(
                    "Error in event listener: " . $e->getMessage(),
                    'error',
                    [
                        'event' => $event->getName(),
                        'listener' => get_class($listener),
                        'exception' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]
                );

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
        $eventData = [
            'name' => $event->getName(),
            'payload' => $event->getPayload(),
            'timestamp' => $event->getTimestamp()
        ];

        $this->eventLog[] = $eventData;
        
        $this->monitor->recordMetric('lifecycle.event.dispatched', 1, [
            'event' => $event->getName()
        ]);
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

    protected function recordMetric(string $name, $value, array $tags = []): void
    {
        $this->monitor->recordMetric($name, $value, $tags);
    }

    protected function recordError(string $message, string $level = 'error', array $context = []): void
    {
        $this->monitor->recordError($message, $level, $context);
    }
}

class CacheListener extends BaseLifecycleListener
{
    private TemplateCacheManager $cache;

    public function __construct(
        TemplateMonitoringService $monitor,
        TemplateCacheManager $cache
    ) {
        parent::__construct($monitor);
        $this->cache = $cache;
    }

    public function getSubscribedEvents(): array
    {
        return [
            'template.cache.hit',
            'template.cache.miss',
            'template.cache.write',
            'template.cache.error'
        ];
    }

    public function handleEvent(LifecycleEventInterface $event): void
    {
        match($event->getName()) {
            'template.cache.hit' => $this->handleCacheHit($event),
            'template.cache.miss' => $this->handleCacheMiss($event),
            'template.cache.write' => $this->handleCacheWrite($event),
            'template.cache.error' => $this->handleCacheError($event),
            default => null
        };
    }

    private function handleCacheHit(LifecycleEventInterface $event): void
    {
        $this->recordMetric('template.cache.hits', 1, [
            'template' => $event->getPayload()['template'] ?? 'unknown'
        ]);
    }

    private function handleCacheMiss(LifecycleEventInterface $event): void
    {
        $this->recordMetric('template.cache.misses', 1, [
            'template' => $event->getPayload()['template'] ?? 'unknown'
        ]);
    }

    private function handleCacheWrite(LifecycleEventInterface $event): void
    {
        $this->recordMetric('template.cache.writes', 1, [
            'template' => $event->getPayload()['template'] ?? 'unknown',
            'size' => $event->getPayload()['size'] ?? 0
        ]);
    }

    private function handleCacheError(LifecycleEventInterface $event): void
    {
        $this->recordError(
            $event->getPayload()['message'] ?? 'Unknown cache error',
            'error',
            $event->getPayload()
        );
    }
}

class SecurityListener extends BaseLifecycleListener
{
    private SecurityManagerInterface $security;

    public function __construct(
        TemplateMonitoringService $monitor,
        SecurityManagerInterface $security
    ) {
        parent::__construct($monitor);
        $this->security = $security;
    }

    public function getSubscribedEvents(): array
    {
        return [
            'template.security.validation',
            'template.security.violation',
            'template.security.error'
        ];
    }

    public function handleEvent(LifecycleEventInterface $event): void
    {
        match($event->getName()) {
            'template.security.validation' => $this->handleSecurityValidation($event),
            'template.security.violation' => $this->handleSecurityViolation($event),
            'template.security.error' => $this->handleSecurityError($event),
            default => null
        };
    }

    private function handleSecurityValidation(LifecycleEventInterface $event): void
    {
        $this->recordMetric('template.security.validations', 1, [
            'template' => $event->getPayload()['template'] ?? 'unknown',
            'rules_checked' => $event->getPayload()['rules_checked'] ?? 0
        ]);
    }

    private function handleSecurityViolation(LifecycleEventInterface $event): void
    {
        $this->recordMetric('template.security.violations', 1, [
            'template' => $event->getPayload()['template'] ?? 'unknown',
            'violation_type' => $event->getPayload()['type'] ?? 'unknown'
        ]);

        $this->recordError(
            'Security violation detected',
            'critical',
            $event->getPayload()
        );
    }

    private function handleSecurityError(LifecycleEventInterface $event): void
    {
        $this->recordError(
            $event->getPayload()['message'] ?? 'Unknown security error',
            'critical',
            $event->getPayload()
        );
    }
}