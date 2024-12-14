<?php

namespace App\Core\Events;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Monitoring\MonitoringSystem;
use App\Core\Exceptions\{EventException, SecurityException};
use Illuminate\Support\Facades\{DB, Log};

class EventManager implements EventManagerInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private MonitoringSystem $monitor;
    private array $listeners = [];
    private array $activeEvents = [];
    private array $config;

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        MonitoringSystem $monitor,
        array $config = []
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->monitor = $monitor;
        $this->config = array_merge([
            'max_listeners' => 100,
            'max_recursion' => 10,
            'timeout' => 30,
            'async_threshold' => 1000,
            'retry_attempts' => 3
        ], $config);
    }

    public function dispatch(string $eventName, array $payload = []): void
    {
        $this->security->executeCriticalOperation(
            function() use ($eventName, $payload) {
                $eventId = $this->generateEventId();
                
                $this->validateEvent($eventName, $payload);
                
                $monitoringId = $this->monitor->startOperation(
                    'event_dispatch',
                    ['event' => $eventName]
                );
                
                try {
                    $this->processEvent($eventId, $eventName, $payload);
                } catch (\Exception $e) {
                    $this->handleEventError($eventId, $eventName, $e);
                    throw $e;
                } finally {
                    $this->monitor->stopOperation($monitoringId);
                    $this->cleanupEvent($eventId);
                }
            },
            ['operation' => 'event_dispatch']
        );
    }

    public function listen(string $eventName, callable $listener, array $options = []): string
    {
        $listenerId = $this->generateListenerId();
        
        $this->validateListener($eventName, $listener);
        
        $this->listeners[$eventName][$listenerId] = [
            'callback' => $listener,
            'priority' => $options['priority'] ?? 0,
            'async' => $options['async'] ?? false,
            'retry' => $options['retry'] ?? true
        ];
        
        uasort($this->listeners[$eventName], fn($a, $b) => 
            $b['priority'] <=> $a['priority']
        );
        
        return $listenerId;
    }

    public function remove(string $eventName, string $listenerId): void
    {
        if (isset($this->listeners[$eventName][$listenerId])) {
            unset($this->listeners[$eventName][$listenerId]);
        }
    }

    public function getActiveEvents(): array
    {
        return array_map(function($event) {
            return [
                'name' => $event['name'],
                'start_time' => $event['start_time'],
                'duration' => microtime(true) - $event['start_time'],
                'listeners_count' => count($event['processed_listeners'])
            ];
        }, $this->activeEvents);
    }

    protected function processEvent(string $eventId, string $eventName, array $payload): void
    {
        $this->activeEvents[$eventId] = [
            'name' => $eventName,
            'start_time' => microtime(true),
            'processed_listeners' => []
        ];

        if (!isset($this->listeners[$eventName])) {
            return;
        }

        foreach ($this->listeners[$eventName] as $listenerId => $listener) {
            if ($this->shouldProcessAsync($listener, $payload)) {
                $this->dispatchAsync($eventId, $eventName, $listenerId, $payload);
            } else {
                $this->executeListener($eventId, $eventName, $listenerId, $payload);
            }
        }
    }

    protected function executeListener(
        string $eventId,
        string $eventName,
        string $listenerId,
        array $payload
    ): void {
        $listener = $this->listeners[$eventName][$listenerId];
        $startTime = microtime(true);
        
        try {
            $result = ($listener['callback'])($payload);
            
            $this->logListenerExecution(
                $eventId,
                $eventName,
                $listenerId,
                $startTime,
                null
            );
            
            return $result;
        } catch (\Exception $e) {
            $this->handleListenerError(
                $eventId,
                $eventName,
                $listenerId,
                $e
            );
            
            if ($listener['retry']) {
                $this->retryListener(
                    $eventId,
                    $eventName,
                    $listenerId,
                    $payload
                );
            }
            
            throw $e;
        }
    }

    protected function dispatchAsync(
        string $eventId,
        string $eventName,
        string $listenerId,
        array $payload
    ): void {
        $job = new AsyncEventListener(
            $eventId,
            $eventName,
            $listenerId,
            $payload
        );
        
        dispatch($job)->onQueue('events');
    }

    protected function retryListener(
        string $eventId,
        string $eventName,
        string $listenerId,
        array $payload
    ): void {
        $attempts = 0;
        $maxAttempts = $this->config['retry_attempts'];
        
        while ($attempts < $maxAttempts) {
            try {
                $this->executeListener(
                    $eventId,
                    $eventName,
                    $listenerId,
                    $payload
                );
                return;
            } catch (\Exception $e) {
                $attempts++;
                if ($attempts === $maxAttempts) {
                    throw $e;
                }
                usleep(100000 * $attempts); // Exponential backoff
            }
        }
    }

    protected function validateEvent(string $eventName, array $payload): void
    {
        if (empty($eventName)) {
            throw new EventException('Event name cannot be empty');
        }

        if ($this->getRecursionDepth($eventName) > $this->config['max_recursion']) {
            throw new EventException('Maximum event recursion depth exceeded');
        }

        if (count($payload) > 1000) {
            throw new EventException('Event payload too large');
        }

        foreach ($payload as $key => $value) {
            if ($this->isUnsafePayload($value)) {
                throw new SecurityException('Unsafe event payload detected');
            }
        }
    }

    protected function validateListener(string $eventName, callable $listener): void
    {
        if (isset($this->listeners[$eventName]) && 
            count($this->listeners[$eventName]) >= $this->config['max_listeners']) {
            throw new EventException('Maximum listeners reached for event');
        }
    }

    protected function shouldProcessAsync(array $listener, array $payload): bool
    {
        return $listener['async'] || 
               $this->isLargePayload($payload) || 
               $this->isHighLoad();
    }

    protected function isLargePayload(array $payload): bool
    {
        return strlen(serialize($payload)) > $this->config['async_threshold'];
    }

    protected function isHighLoad(): bool
    {
        $load = sys_getloadavg();
        return $load[0] > 0.7;
    }

    protected function getRecursionDepth(string $eventName): int
    {
        $depth = 0;
        $current = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        
        foreach ($current as $trace) {
            if (isset($trace['class']) && 
                $trace['class'] === self::class && 
                $trace['function'] === 'dispatch') {
                $depth++;
            }
        }
        
        return $depth;
    }

    protected function generateEventId(): string
    {
        return uniqid('evt_', true);
    }

    protected function generateListenerId(): string
    {
        return uniqid('lsn_', true);
    }

    protected function handleEventError(
        string $eventId,
        string $eventName,
        \Exception $e
    ): void {
        Log::error('Event processing failed', [
            'event_id' => $eventId,
            'event_name' => $eventName,
            'error' => $e->getMessage()
        ]);
    }

    protected function handleListenerError(
        string $eventId,
        string $eventName,
        string $listenerId,
        \Exception $e
    ): void {
        Log::error('Event listener failed', [
            'event_id' => $eventId,
            'event_name' => $eventName,
            'listener_id' => $listenerId,
            'error' => $e->getMessage()
        ]);
    }

    protected function cleanupEvent(string $eventId): void
    {
        unset($this->activeEvents[$eventId]);
    }

    protected function isUnsafePayload($value): bool
    {
        if (is_string($value)) {
            return preg_match('/[\x00-\x1F\x7F]/', $value) || 
                   strlen($value) > 10000;
        } elseif (is_array($value)) {
            foreach ($value as $item) {
                if ($this->isUnsafePayload($item)) {
                    return true;
                }
            }
        }
        return false;
    }

    protected function logListenerExecution(
        string $eventId,
        string $eventName,
        string $listenerId,
        float $startTime,
        ?\Exception $error
    ): void {
        $duration = microtime(true) - $startTime;
        
        $this->activeEvents[$eventId]['processed_listeners'][$listenerId] = [
            'duration' => $duration,
            'error' => $error ? $error->getMessage() : null
        ];
        
        if ($this->config['logging'] ?? true) {
            Log::info('Event listener executed', [
                'event_id' => $eventId,
                'event_name' => $eventName,
                'listener_id' => $listenerId,
                'duration' => $duration,
                'error' => $error ? $error->getMessage() : null
            ]);
        }
    }
}
