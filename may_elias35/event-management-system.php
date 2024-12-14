namespace App\Core\Events;

use App\Core\Security\SecurityManager;
use App\Core\Services\ValidationService;
use App\Core\Services\MetricsCollector;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;

class EventManager
{
    private SecurityManager $security;
    private ValidationService $validator;
    private MetricsCollector $metrics;
    private array $config;
    private array $eventBuffer = [];

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        MetricsCollector $metrics,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->metrics = $metrics;
        $this->config = $config;
    }

    public function dispatch(string $eventName, array $payload, array $context): void
    {
        $this->security->executeSecureOperation(
            function() use ($eventName, $payload, $context) {
                $validatedPayload = $this->validateEventPayload($eventName, $payload);
                $eventId = $this->generateEventId();
                
                $this->logEvent($eventId, $eventName, $validatedPayload, $context);
                $this->trackEvent($eventId, $eventName);
                
                if ($this->isHighPriorityEvent($eventName)) {
                    $this->processImmediately($eventId, $eventName, $validatedPayload);
                } else {
                    $this->bufferEvent($eventId, $eventName, $validatedPayload);
                }
                
                $this->metrics->recordEvent($eventName, $validatedPayload);
            },
            $context
        );
    }

    public function subscribe(string $eventName, callable $handler, array $options = []): string
    {
        $subscriberId = $this->generateSubscriberId($eventName);
        
        $this->validateSubscription($eventName, $handler, $options);
        
        $this->registerSubscriber($subscriberId, $eventName, $handler, $options);
        
        return $subscriberId;
    }

    public function processEvents(): void
    {
        if (empty($this->eventBuffer)) {
            return;
        }

        foreach ($this->eventBuffer as $event) {
            try {
                $this->processEvent($event);
                $this->markEventProcessed($event['id']);
            } catch (\Throwable $e) {
                $this->handleEventError($event, $e);
            }
        }

        $this->eventBuffer = [];
    }

    protected function validateEventPayload(string $eventName, array $payload): array
    {
        if (!isset($this->config['events'][$eventName])) {
            throw new EventException("Unknown event: {$eventName}");
        }

        return $this->validator->validate(
            $payload,
            $this->config['events'][$eventName]['validation_rules']
        );
    }

    protected function validateSubscription(
        string $eventName,
        callable $handler,
        array $options
    ): void {
        if (!isset($this->config['events'][$eventName])) {
            throw new EventException("Cannot subscribe to unknown event: {$eventName}");
        }

        if (!$this->validator->validateHandler($handler)) {
            throw new EventException("Invalid event handler");
        }

        foreach ($options as $key => $value) {
            if (!$this->validator->validateOption($key, $value)) {
                throw new EventException("Invalid subscription option: {$key}");
            }
        }
    }

    protected function processImmediately(
        string $eventId,
        string $eventName,
        array $payload
    ): void {
        $subscribers = $this->getEventSubscribers($eventName);
        
        foreach ($subscribers as $subscriber) {
            try {
                $this->executeHandler(
                    $subscriber['handler'],
                    $payload,
                    $subscriber['options']
                );
                
                $this->logSuccessfulExecution($eventId, $subscriber['id']);
            } catch (\Throwable $e) {
                $this->handleExecutionError($eventId, $subscriber['id'], $e);
            }
        }
    }

    protected function bufferEvent(
        string $eventId,
        string $eventName,
        array $payload
    ): void {
        $this->eventBuffer[] = [
            'id' => $eventId,
            'name' => $eventName,
            'payload' => $payload,
            'timestamp' => microtime(true)
        ];

        if (count($this->eventBuffer) >= $this->config['buffer_size']) {
            $this->processEvents();
        }
    }

    protected function executeHandler(
        callable $handler,
        array $payload,
        array $options
    ): void {
        if ($options['async'] ?? false) {
            $this->dispatchAsync($handler, $payload);
        } else {
            $handler($payload);
        }
    }

    protected function dispatchAsync(callable $handler, array $payload): void
    {
        Event::dispatch(new AsyncEventJob($handler, $payload));
    }

    protected function logEvent(
        string $eventId,
        string $eventName,
        array $payload,
        array $context
    ): void {
        Log::info('Event dispatched', [
            'event_id' => $eventId,
            'event_name' => $eventName,
            'payload' => $this->sanitizePayload($payload),
            'context' => $context,
            'timestamp' => now()->toIso8601String()
        ]);
    }

    protected function trackEvent(string $eventId, string $eventName): void
    {
        Redis::pipeline(function($pipe) use ($eventId, $eventName) {
            $pipe->hincrby('events:count', $eventName, 1);
            $pipe->zadd('events:timeline', microtime(true), $eventId);
        });
    }

    protected function markEventProcessed(string $eventId): void
    {
        Redis::hset('events:status', $eventId, 'processed');
    }

    protected function getEventSubscribers(string $eventName): array
    {
        return $this->config['events'][$eventName]['subscribers'] ?? [];
    }

    protected function registerSubscriber(
        string $subscriberId,
        string $eventName,
        callable $handler,
        array $options
    ): void {
        $this->config['events'][$eventName]['subscribers'][] = [
            'id' => $subscriberId,
            'handler' => $handler,
            'options' => $options
        ];
    }

    protected function handleEventError(array $event, \Throwable $e): void
    {
        Log::error('Event processing failed', [
            'event_id' => $event['id'],
            'event_name' => $event['name'],
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->metrics->incrementEventErrors($event['name']);
    }

    protected function handleExecutionError(
        string $eventId,
        string $subscriberId,
        \Throwable $e
    ): void {
        Log::error('Event handler execution failed', [
            'event_id' => $eventId,
            'subscriber_id' => $subscriberId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    protected function generateEventId(): string
    {
        return hash('sha256', uniqid('event_', true));
    }

    protected function generateSubscriberId(string $eventName): string
    {
        return hash('sha256', $eventName . uniqid('sub_', true));
    }

    protected function isHighPriorityEvent(string $eventName): bool
    {
        return in_array($eventName, $this->config['high_priority_events']);
    }

    protected function sanitizePayload(array $payload): array
    {
        return array_map(function($value) {
            if (is_string($value)) {
                return $this->validator->sanitize($value);
            }
            if (is_array($value)) {
                return $this->sanitizePayload($value);
            }
            return $value;
        }, $payload);
    }
}
