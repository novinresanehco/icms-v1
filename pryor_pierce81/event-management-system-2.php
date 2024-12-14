<?php

namespace App\Core\Events;

use Illuminate\Support\Facades\{DB, Cache, Queue};
use App\Core\Security\SecurityManager;
use App\Core\Monitoring\MonitoringService;

class EventManager implements EventInterface
{
    protected SecurityManager $security;
    protected MonitoringService $monitor;
    protected EventRepository $repository;
    protected EventDispatcher $dispatcher;
    protected array $config;
    
    public function __construct(
        SecurityManager $security,
        MonitoringService $monitor,
        EventRepository $repository,
        EventDispatcher $dispatcher,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->repository = $repository;
        $this->dispatcher = $dispatcher;
        $this->config = $config;
    }

    public function dispatch(string $event, array $data = [], array $options = []): void
    {
        $this->security->executeCriticalOperation(function() use ($event, $data, $options) {
            $this->validateEvent($event);
            $eventData = $this->prepareEventData($event, $data);
            
            if ($this->shouldQueueEvent($event)) {
                $this->queueEvent($event, $eventData, $options);
            } else {
                $this->processEvent($event, $eventData, $options);
            }
        });
    }

    public function subscribe(string $event, callable $handler, array $options = []): void
    {
        $this->security->executeCriticalOperation(function() use ($event, $handler, $options) {
            $this->validateEvent($event);
            $this->validateHandler($handler);
            
            $subscription = [
                'event' => $event,
                'handler' => $handler,
                'priority' => $options['priority'] ?? 0,
                'condition' => $options['condition'] ?? null
            ];

            $this->dispatcher->subscribe($subscription);
            Cache::tags(['events'])->flush();
        });
    }

    public function unsubscribe(string $event, callable $handler): void
    {
        $this->security->executeCriticalOperation(function() use ($event, $handler) {
            $this->dispatcher->unsubscribe($event, $handler);
            Cache::tags(['events'])->flush();
        });
    }

    protected function processEvent(string $event, array $data, array $options): void
    {
        DB::transaction(function() use ($event, $data, $options) {
            $record = $this->repository->create([
                'event' => $event,
                'data' => $data,
                'metadata' => $this->getEventMetadata($options)
            ]);

            try {
                $this->dispatcher->dispatch($event, $data);
                $this->repository->markAsProcessed($record->id);
                $this->monitor->recordEventSuccess($event);
                
            } catch (\Exception $e) {
                $this->handleEventFailure($record, $e);
                throw $e;
            }
        });
    }

    protected function queueEvent(string $event, array $data, array $options): void
    {
        $job = new ProcessEventJob($event, $data, $options);
        
        $queue = $options['queue'] ?? $this->getEventQueue($event);
        $delay = $options['delay'] ?? $this->getEventDelay($event);
        
        Queue::later($delay, $job->onQueue($queue));
    }

    protected function validateEvent(string $event): void
    {
        if (!in_array($event, $this->config['registered_events'])) {
            throw new InvalidEventException("Invalid event type: {$event}");
        }
    }

    protected function validateHandler(callable $handler): void
    {
        $reflection = new \ReflectionFunction($handler);
        
        if ($reflection->getNumberOfRequiredParameters() !== 2) {
            throw new InvalidHandlerException('Event handler must accept event and data parameters');
        }
    }

    protected function prepareEventData(string $event, array $data): array
    {
        return [
            'event' => $event,
            'data' => $this->sanitizeEventData($data),
            'user_id' => auth()->id(),
            'ip_address' => request()->ip(),
            'timestamp' => now(),
            'trace_id' => $this->monitor->getCurrentTraceId()
        ];
    }

    protected function sanitizeEventData(array $data): array
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            if ($this->isAllowedKey($key)) {
                $sanitized[$key] = $this->sanitizeValue($value);
            }
        }
        
        return $sanitized;
    }

    protected function sanitizeValue($value)
    {
        if (is_array($value)) {
            return $this->sanitizeEventData($value);
        }
        
        if (is_string($value)) {
            return $this->sanitizeString($value);
        }
        
        return $value;
    }

    protected function sanitizeString(string $value): string
    {
        $value = strip_tags($value);
        $value = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return substr($value, 0, $this->config['max_string_length']);
    }

    protected function isAllowedKey(string $key): bool
    {
        return !in_array($key, $this->config['forbidden_keys']);
    }

    protected function shouldQueueEvent(string $event): bool
    {
        return in_array($event, $this->config['queued_events']);
    }

    protected function getEventQueue(string $event): string
    {
        return $this->config['event_queues'][$event] ?? 'default';
    }

    protected function getEventDelay(string $event): int
    {
        return $this->config['event_delays'][$event] ?? 0;
    }

    protected function getEventMetadata(array $options): array
    {
        return [
            'trace_id' => $this->monitor->getCurrentTraceId(),
            'source' => $options['source'] ?? 'system',
            'priority' => $options['priority'] ?? 'normal',
            'retry_count' => 0,
            'performance' => $this->monitor->getCurrentMetrics()
        ];
    }

    protected function handleEventFailure($record, \Exception $e): void
    {
        $this->repository->markAsFailed($record->id, [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        $this->monitor->recordEventFailure($record->event, $e);
        
        if ($this->shouldRetryEvent($record)) {
            $this->scheduleEventRetry($record);
        }
    }

    protected function shouldRetryEvent($record): bool
    {
        $maxRetries = $this->config['max_retries'][$record->event] ?? 3;
        return $record->metadata['retry_count'] < $maxRetries;
    }

    protected function scheduleEventRetry($record): void
    {
        $delay = $this->calculateRetryDelay($record);
        
        Queue::later($delay, new RetryEventJob(
            $record->id,
            $record->event,
            $record->data,
            [
                'retry_count' => $record->metadata['retry_count'] + 1
            ]
        ));
    }

    protected function calculateRetryDelay($record): int
    {
        $baseDelay = $this->config['retry_delay'] ?? 60;
        return $baseDelay * (2 ** $record->metadata['retry_count']);
    }
}
