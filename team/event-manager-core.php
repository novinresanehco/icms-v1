<?php

namespace App\Core\Events;

use Illuminate\Support\Facades\{Cache, DB, Log, Redis};
use App\Core\Security\SecurityManager;
use App\Core\Services\{ValidationService, NotificationService};
use App\Core\Exceptions\{EventException, SecurityException};

class EventManager implements EventInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private NotificationService $notifier;
    
    private const CACHE_TTL = 300;
    private const MAX_RETRY = 3;
    private const CRITICAL_EVENTS = [
        'security.breach',
        'system.error',
        'data.corruption',
        'auth.failure'
    ];

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        NotificationService $notifier
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->notifier = $notifier;
    }

    public function dispatch(string $event, array $data = []): void
    {
        $this->security->executeCriticalOperation(
            fn() => $this->executeDispatch($event, $data),
            ['action' => 'event.dispatch', 'event' => $event]
        );
    }

    protected function executeDispatch(string $event, array $data): void
    {
        $this->validateEvent($event, $data);
        
        DB::beginTransaction();
        try {
            // Record event
            $eventId = $this->recordEvent($event, $data);
            
            // Process handlers
            $this->processEventHandlers($event, $data, $eventId);
            
            // Handle critical events
            if ($this->isCriticalEvent($event)) {
                $this->handleCriticalEvent($event, $data, $eventId);
            }
            
            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleDispatchFailure($event, $data, $e);
            throw $e;
        }
    }

    public function subscribe(string $event, callable $handler): string
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeSubscribe($event, $handler),
            ['action' => 'event.subscribe', 'event' => $event]
        );
    }

    protected function executeSubscribe(string $event, callable $handler): string
    {
        $this->validateEventName($event);
        
        $subscriberId = $this->generateSubscriberId();

        Redis::hset(
            "event_subscribers:{$event}",
            $subscriberId,
            serialize($handler)
        );

        return $subscriberId;
    }

    public function unsubscribe(string $event, string $subscriberId): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeUnsubscribe($event, $subscriberId),
            ['action' => 'event.unsubscribe', 'event' => $event, 'subscriber' => $subscriberId]
        );
    }

    protected function executeUnsubscribe(string $event, string $subscriberId): bool
    {
        $this->validateEventName($event);
        
        return (bool)Redis::hdel(
            "event_subscribers:{$event}",
            $subscriberId
        );
    }

    protected function validateEvent(string $event, array $data): void
    {
        $this->validateEventName($event);
        
        if (!$this->validator->validateEventData($data)) {
            throw new EventException('Invalid event data');
        }
    }

    protected function validateEventName(string $event): void
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9._-]*$/', $event)) {
            throw new EventException('Invalid event name');
        }
    }

    protected function recordEvent(string $event, array $data): string
    {
        $eventId = $this->generateEventId();
        
        DB::table('system_events')->insert([
            'event_id' => $eventId,
            'event' => $event,
            'data' => json_encode($data),
            'user_id' => auth()->id(),
            'ip_address' => request()->ip(),
            'created_at' => now()
        ]);

        return $eventId;
    }

    protected function processEventHandlers(string $event, array $data, string $eventId): void
    {
        $subscribers = Redis::hgetall("event_subscribers:{$event}");
        
        foreach ($subscribers as $subscriberId => $serializedHandler) {
            try {
                $handler = unserialize($serializedHandler);
                $this->executeHandler($handler, $event, $data, $eventId);
            } catch (\Exception $e) {
                $this->handleSubscriberFailure($event, $subscriberId, $e);
            }
        }
    }

    protected function executeHandler(callable $handler, string $event, array $data, string $eventId): void
    {
        $attempts = 0;
        $maxRetries = $this->getMaxRetries($event);
        
        while ($attempts < $maxRetries) {
            try {
                $handler($event, $data, $eventId);
                return;
            } catch (\Exception $e) {
                $attempts++;
                if ($attempts >= $maxRetries) {
                    throw $e;
                }
                usleep(100000 * pow(2, $attempts)); // Exponential backoff
            }
        }
    }

    protected function handleCriticalEvent(string $event, array $data, string $eventId): void
    {
        // Log critical event
        Log::critical('Critical event occurred', [
            'event' => $event,
            'event_id' => $eventId,
            'data' => $data
        ]);

        // Notify relevant parties
        $this->notifier->notifyCriticalEvent($event, $data);

        // Store in separate critical events log
        DB::table('critical_events')->insert([
            'event_id' => $eventId,
            'event' => $event,
            'data' => json_encode($data),
            'handled' => false,
            'created_at' => now()
        ]);

        // Cache for real-time monitoring
        Cache::put(
            "critical_event:{$event}:" . time(),
            $data,
            now()->addDay()
        );
    }

    protected function handleDispatchFailure(string $event, array $data, \Exception $e): void
    {
        Log::error('Event dispatch failed', [
            'event' => $event,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        if ($this->isCriticalEvent($event)) {
            $this->handleCriticalDispatchFailure($event, $data, $e);
        }
    }

    protected function handleSubscriberFailure(string $event, string $subscriberId, \Exception $e): void
    {
        Log::error('Event subscriber failed', [
            'event' => $event,
            'subscriber' => $subscriberId,
            'error' => $e->getMessage()
        ]);

        $failures = Redis::hincrby(
            "subscriber_failures:{$event}",
            $subscriberId,
            1
        );

        if ($failures >= self::MAX_RETRY) {
            $this->disableSubscriber($event, $subscriberId);
        }
    }

    protected function handleCriticalDispatchFailure(string $event, array $data, \Exception $e): void
    {
        // Store failed event for retry
        DB::table('failed_events')->insert([
            'event' => $event,
            'data' => json_encode($data),
            'error' => $e->getMessage(),
            'created_at' => now()
        ]);

        // Notify emergency contacts
        $this->notifier->notifyEmergencyContacts(
            "Critical event dispatch failure: {$event}",
            [
                'error' => $e->getMessage(),
                'data' => $data
            ]
        );
    }

    protected function disableSubscriber(string $event, string $subscriberId): void
    {
        Redis::hdel("event_subscribers:{$event}", $subscriberId);
        
        Log::warning('Subscriber disabled due to failures', [
            'event' => $event,
            'subscriber' => $subscriberId
        ]);
    }

    protected function isCriticalEvent(string $event): bool
    {
        return in_array($event, self::CRITICAL_EVENTS);
    }

    protected function getMaxRetries(string $event): int
    {
        return $this->isCriticalEvent($event) ? 
            self::MAX_RETRY * 2 : 
            self::MAX_RETRY;
    }

    protected function generateEventId(): string
    {
        return uniqid('evt_', true);
    }

    protected function generateSubscriberId(): string
    {
        return uniqid('sub_', true);
    }
}
