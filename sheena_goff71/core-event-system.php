<?php

namespace App\Core\Events;

use Illuminate\Support\Facades\{Queue, Log, Cache};
use App\Core\Security\SecurityManager;
use App\Core\Interfaces\{EventManagerInterface, QueueInterface};
use App\Core\Exceptions\{EventException, QueueException};

class EventManager implements EventManagerInterface
{
    private SecurityManager $security;
    private EventDispatcher $dispatcher;
    private QueueInterface $queue;
    private ValidationService $validator;
    private array $config;

    public function __construct(
        SecurityManager $security,
        EventDispatcher $dispatcher,
        QueueInterface $queue,
        ValidationService $validator,
        array $config
    ) {
        $this->security = $security;
        $this->dispatcher = $dispatcher;
        $this->queue = $queue;
        $this->validator = $validator;
        $this->config = $config;
    }

    public function dispatch(string $event, array $payload = []): void
    {
        $eventId = $this->generateEventId();

        $this->security->executeCriticalOperation(
            fn() => $this->processEvent($event, $payload, $eventId),
            ['action' => 'dispatch_event', 'event_id' => $eventId]
        );
    }

    public function broadcast(string $event, array $payload = []): void
    {
        $eventId = $this->generateEventId();

        $this->security->executeCriticalOperation(
            fn() => $this->processBroadcast($event, $payload, $eventId),
            ['action' => 'broadcast_event', 'event_id' => $eventId]
        );
    }

    protected function processEvent(string $event, array $payload, string $eventId): void
    {
        try {
            $this->validateEvent($event, $payload);
            $this->trackEventLimit($event);

            $startTime = microtime(true);
            
            if ($this->shouldQueue($event)) {
                $this->queueEvent($event, $payload, $eventId);
            } else {
                $this->dispatchImmediately($event, $payload, $eventId);
            }

            $this->logEventMetrics($event, $eventId, microtime(true) - $startTime);

        } catch (\Exception $e) {
            $this->handleEventFailure($e, $event, $eventId);
            throw new EventException('Event processing failed: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function processBroadcast(string $event, array $payload, string $eventId): void
    {
        try {
            $this->validateBroadcast($event, $payload);
            $this->trackBroadcastLimit($event);

            $channels = $this->determineChannels($event, $payload);
            $message = $this->prepareBroadcastMessage($event, $payload, $eventId);

            foreach ($channels as $channel) {
                $this->broadcastToChannel($channel, $message);
            }

            $this->logBroadcastMetrics($event, $eventId, $channels);

        } catch (\Exception $e) {
            $this->handleBroadcastFailure($e, $event, $eventId);
            throw new EventException('Broadcast failed: ' . $e->getMessage(), 0, $e);
        }
    }

    protected function validateEvent(string $event, array $payload): void
    {
        if (!$this->validator->validateEventName($event)) {
            throw new EventException('Invalid event name');
        }

        if (!$this->validator->validateEventPayload($payload)) {
            throw new EventException('Invalid event payload');
        }
    }

    protected function validateBroadcast(string $event, array $payload): void
    {
        if (!$this->validator->validateBroadcastEvent($event)) {
            throw new EventException('Invalid broadcast event');
        }

        if (!$this->validator->validateBroadcastPayload($payload)) {
            throw new EventException('Invalid broadcast payload');
        }
    }

    protected function shouldQueue(string $event): bool
    {
        return in_array($event, $this->config['queued_events']) ||
               $this->isHighLoadPeriod();
    }

    protected function queueEvent(string $event, array $payload, string $eventId): void
    {
        $job = new ProcessEventJob($event, $payload, $eventId);
        
        $this->queue->push(
            $job,
            $this->getQueuePriority($event),
            $this->getQueueName($event)
        );
    }

    protected function dispatchImmediately(string $event, array $payload, string $eventId): void
    {
        $this->dispatcher->dispatch(
            $event,
            $this->prepareEventPayload($payload, $eventId)
        );
    }

    protected function determineChannels(string $event, array $payload): array
    {
        $channels = $this->config['broadcast_channels'][$event] ?? ['default'];

        if (isset($payload['channels'])) {
            $channels = array_merge($channels, $payload['channels']);
        }

        return array_unique($channels);
    }

    protected function prepareBroadcastMessage(string $event, array $payload, string $eventId): array
    {
        return [
            'event' => $event,
            'payload' => $payload,
            'event_id' => $eventId,
            'timestamp' => microtime(true),
            'signature' => $this->generateSignature($event, $payload, $eventId)
        ];
    }

    protected function broadcastToChannel(string $channel, array $message): void
    {
        if (!$this->isChannelAvailable($channel)) {
            throw new EventException("Channel not available: {$channel}");
        }

        $this->dispatcher->broadcast($channel, $message);
    }

    protected function prepareEventPayload(array $payload, string $eventId): array
    {
        return array_merge($payload, [
            'event_id' => $eventId,
            'timestamp' => microtime(true),
            'context' => $this->getEventContext()
        ]);
    }

    protected function getEventContext(): array
    {
        return [
            'request_id' => request()->id(),
            'user_id' => auth()->id(),
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent()
        ];
    }

    protected function trackEventLimit(string $event): void
    {
        $key = "events:limit:{$event}:" . date('YmdH');
        
        if (Cache::increment($key) > $this->config['event_hourly_limit']) {
            throw new EventException('Event limit exceeded');
        }
    }

    protected function trackBroadcastLimit(string $event): void
    {
        $key = "broadcast:limit:{$event}:" . date('YmdH');
        
        if (Cache::increment($key) > $this->config['broadcast_hourly_limit']) {
            throw new EventException('Broadcast limit exceeded');
        }
    }

    protected function isHighLoadPeriod(): bool
    {
        return $this->queue->size() > $this->config['high_load_threshold'];
    }

    protected function isChannelAvailable(string $channel): bool
    {
        return $this->dispatcher->channelStatus($channel) === 'available';
    }

    protected function getQueuePriority(string $event): int
    {
        return $this->config['event_priorities'][$event] ?? 0;
    }

    protected function getQueueName(string $event): string
    {
        return $this->config['event_queues'][$event] ?? 'default';
    }

    protected function generateSignature(string $event, array $payload, string $eventId): string
    {
        return hash_hmac(
            'sha256',
            json_encode(compact('event', 'payload', 'eventId')),
            $this->config['broadcast_key']
        );
    }

    protected function generateEventId(): string
    {
        return uniqid('evt_', true);
    }

    protected function handleEventFailure(\Exception $e, string $event, string $eventId): void
    {
        Log::error('Event processing failed', [
            'event' => $event,
            'event_id' => $eventId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($this->isSystemCriticalEvent($event)) {
            $this->triggerEmergencyProtocol($e, $event, $eventId);
        }
    }

    protected function handleBroadcastFailure(\Exception $e, string $event, string $eventId): void
    {
        Log::error('Broadcast failed', [
            'event' => $event,
            'event_id' => $eventId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function logEventMetrics(string $event, string $eventId, float $duration): void
    {
        $this->updateEventStats($event, $duration);

        if ($duration > $this->config['slow_event_threshold']) {
            Log::warning('Slow event detected', [
                'event' => $event,
                'event_id' => $eventId,
                'duration' => $duration
            ]);
        }
    }

    protected function logBroadcastMetrics(string $event, string $eventId, array $channels): void
    {
        foreach ($channels as $channel) {
            $this->updateBroadcastStats($channel);
        }
    }

    protected function updateEventStats(string $event, float $duration): void
    {
        $key = "events:stats:{$event}";
        
        Cache::tags(['events', 'stats'])->remember($key, 3600, function() {
            return ['count' => 0, 'total_time' => 0];
        });

        Cache::tags(['events', 'stats'])->increment("{$key}:count");
        Cache::tags(['events', 'stats'])->increment("{$key}:total_time", $duration);
    }

    protected function updateBroadcastStats(string $channel): void
    {
        Cache::tags(['broadcast', 'stats'])
            ->increment("broadcast:count:{$channel}");
    }

    protected function isSystemCriticalEvent(string $event): bool
    {
        return in_array($event, $this->config['critical_events']);
    }

    protected function triggerEmergencyProtocol(\Exception $e, string $event, string $eventId): void
    {
        $this->security->logSecurityEvent('critical_event_failure', [
            'event' => $event,
            'event_id' => $eventId,
            'error' => $e->getMessage()
        ]);

        if ($this->config['emergency_shutdown_enabled']) {
            $this->initiateEmergencyShutdown($event, $eventId);
        }
    }

    protected function initiateEmergencyShutdown(string $event, string $eventId): void
    {
        Log::critical('Emergency shutdown initiated due to critical event failure', [
            'event' => $event,
            'event_id' => $eventId
        ]);

        Cache::tags(['system', 'emergency'])->flush();
        $this->dispatcher->broadcast('system', ['type' => 'emergency_shutdown']);
    }
}
