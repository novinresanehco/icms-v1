<?php

namespace App\Core\Events;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\SecurityManagerInterface;
use App\Core\Monitoring\MonitoringService;

class EventManager implements EventManagerInterface
{
    private SecurityManagerInterface $security;
    private MonitoringService $monitor;
    private EventStore $store;
    private array $handlers = [];
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        MonitoringService $monitor,
        EventStore $store,
        array $config
    ) {
        $this->security = $security;
        $this->monitor = $monitor;
        $this->store = $store;
        $this->config = $config;
    }

    public function dispatch(Event $event, SecurityContext $context): void
    {
        $operationId = $this->monitor->startOperation('event.dispatch');

        try {
            $this->security->executeCriticalOperation(
                fn() => $this->processEvent($event, $context),
                $context
            );
        } finally {
            $this->monitor->stopOperation($operationId);
        }
    }

    public function subscribe(string $eventType, callable $handler, array $config = []): string
    {
        return $this->security->executeCriticalOperation(
            function() use ($eventType, $handler, $config) {
                $id = $this->generateHandlerId();
                
                $this->handlers[$eventType][$id] = [
                    'handler' => $handler,
                    'config' => array_merge($this->getDefaultConfig(), $config)
                ];

                $this->validateHandler($eventType, $id);
                return $id;
            },
            new SecurityContext('event.subscribe')
        );
    }

    protected function processEvent(Event $event, SecurityContext $context): void
    {
        $this->validateEvent($event);
        
        DB::beginTransaction();
        
        try {
            $storedEvent = $this->store->persist($event, $context);
            
            $results = $this->executeHandlers($storedEvent, $context);
            $this->validateResults($results);
            
            $this->store->markAsProcessed($storedEvent->getId());
            
            DB::commit();
            
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->handleFailure($e, $event, $context);
            throw $e;
        }
    }

    protected function executeHandlers(StoredEvent $event, SecurityContext $context): array
    {
        $results = [];
        $handlers = $this->handlers[$event->getType()] ?? [];

        foreach ($handlers as $id => $config) {
            $span = $this->monitor->startSpan("event.handler.$id");
            
            try {
                $result = $this->executeHandler(
                    $config['handler'],
                    $event,
                    $context,
                    $config['config']
                );
                
                $results[$id] = [
                    'success' => true,
                    'result' => $result
                ];
                
            } catch (\Throwable $e) {
                $results[$id] = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
                
                if ($config['config']['critical'] ?? false) {
                    throw $e;
                }
                
                $this->monitor->recordError('event.handler_failed', [
                    'handler_id' => $id,
                    'event_id' => $event->getId(),
                    'error' => $e->getMessage()
                ]);
            } finally {
                $this->monitor->endSpan($span);
            }
        }

        return $results;
    }

    protected function executeHandler(
        callable $handler,
        StoredEvent $event,
        SecurityContext $context,
        array $config
    ): mixed {
        if ($config['async'] ?? false) {
            return $this->dispatchAsync($handler, $event, $context);
        }

        return $handler($event, $context);
    }

    protected function dispatchAsync(
        callable $handler,
        StoredEvent $event,
        SecurityContext $context
    ): string {
        $jobId = $this->generateJobId();
        
        dispatch(new EventHandlerJob(
            $handler,
            $event,
            $context,
            $jobId
        ));

        return $jobId;
    }

    protected function validateEvent(Event $event): void
    {
        if (!$event->isValid()) {
            throw new EventValidationException('Invalid event data');
        }

        if ($this->isEventDuplicate($event)) {
            throw new DuplicateEventException('Duplicate event detected');
        }
    }

    protected function validateResults(array $results): void
    {
        $failedCritical = false;
        
        foreach ($results as $id => $result) {
            if (!$result['success'] && ($this->handlers[$id]['config']['critical'] ?? false)) {
                $failedCritical = true;
                break;
            }
        }

        if ($failedCritical) {
            throw new CriticalHandlerException('Critical event handler failed');
        }
    }

    protected function handleFailure(\Throwable $e, Event $event, SecurityContext $context): void
    {
        $this->monitor->recordError('event.processing_failed', [
            'event_type' => $event->getType(),
            'error' => $e->getMessage(),
            'context' => $context
        ]);

        if ($this->shouldRetry($e)) {
            $this->scheduleRetry($event, $context);
        }
    }

    protected function shouldRetry(\Throwable $e): bool
    {
        return !($e instanceof ValidationException) && 
               !($e instanceof SecurityException);
    }

    protected function scheduleRetry(Event $event, SecurityContext $context): void
    {
        $retryCount = $event->getRetryCount();
        
        if ($retryCount >= ($this->config['max_retries'] ?? 3)) {
            return;
        }

        $delay = $this->calculateRetryDelay($retryCount);
        
        dispatch(new EventRetryJob(
            $event->incrementRetryCount(),
            $context
        ))->delay($delay);
    }

    protected function calculateRetryDelay(int $retryCount): int
    {
        return min(
            pow(2, $retryCount) * ($this->config['base_retry_delay'] ?? 60),
            $this->config['max_retry_delay'] ?? 3600
        );
    }

    protected function isEventDuplicate(Event $event): bool
    {
        $key = "event:dedup:" . $event->getDeduplicationKey();
        return !Cache::add($key, true, 60);
    }

    protected function generateHandlerId(): string
    {
        return bin2hex(random_bytes(16));
    }

    protected function generateJobId(): string
    {
        return bin2hex(random_bytes(16));
    }

    protected function getDefaultConfig(): array
    {
        return [
            'critical' => false,
            'async' => false,
            'timeout' => 30,
            'retry' => true
        ];
    }
}
