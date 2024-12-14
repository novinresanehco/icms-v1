<?php

namespace App\Core\Events\Metrics;

class EventMetrics
{
    private MetricsCollector $collector;

    public function __construct(MetricsCollector $collector)
    {
        $this->collector = $collector;
    }

    public function recordEventDispatched(Event $event): void
    {
        $this->collector->increment('events.dispatched', [
            'event_type' => $event->getName()
        ]);
    }

    public function recordListenerExecuted(Event $event, EventListener $listener, float $duration): void
    {
        $this->collector->timing('events.listener_duration', $duration, [
            'event_type' => $event->getName(),
            'listener' => get_class($listener)
        ]);
    }

    public function recordListenerError(Event $event, EventListener $listener, \Exception $error): void
    {
        $this->collector->increment('events.listener_errors', [
            'event_type' => $event->getName(),
            'listener' => get_class($listener),
            'error_type' => get_class($error)
        ]);
    }

    public function recordBroadcastAttempt(Event $event, string $channel): void
    {
        $this->collector->increment('events.broadcast_attempts', [
            'event_type' => $event->getName(),
            'channel' => $channel
        ]);
    }

    public function recordBroadcastSuccess(Event $event, string $channel): void
    {
        $this->collector->increment('events.broadcast_success', [
            'event_type' => $event->getName(),
            'channel' => $channel
        ]);
    }

    public function recordBroadcastError(Event $event, string $channel, \Exception $error): void
    {
        $this->collector->increment('events.broadcast_errors', [
            'event_type' => $event->getName(),
            'channel' => $channel,
            'error_type' => get_class($error)
        ]);
    }
}

class EventPerformanceMonitor
{
    private array $thresholds;
    private AlertManager $alertManager;
    private EventMetrics $metrics;

    public function __construct(
        array $thresholds,
        AlertManager $alertManager,
        EventMetrics $metrics
    ) {
        $this->thresholds = $thresholds;
        $this->alertManager = $alertManager;
        $this->metrics = $metrics;
    }

    public function monitorListenerPerformance(Event $event, EventListener $listener, float $duration): void
    {
        $threshold = $this->thresholds[get_class($listener)] ?? $this->thresholds['default'] ?? 1.0;

        if ($duration > $threshold) {
            $this->alertManager->alert(
                'listener_slow_execution',
                [
                    'event' => get_class($event),
                    'listener' => get_class($listener),
                    'duration' => $duration,
                    'threshold' => $threshold
                ]
            );
        }

        $this->metrics->recordListenerExecuted($event, $listener, $duration);
    }

    public function monitorBroadcastPerformance(Event $event, string $channel, float $duration): void
    {
        $threshold = $this->thresholds['broadcast'] ?? 0.5;

        if ($duration > $threshold) {
            $this->alertManager->alert(
                'broadcast_slow_execution',
                [
                    'event' => get_class($event),
                    'channel' => $channel,
                    'duration' => $duration,
                    'threshold' => $threshold
                ]
            );
        }
    }
}
