<?php

namespace App\Core\Events;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Validation\ValidationServiceInterface;
use App\Core\Exception\{EventException, SecurityException};
use Psr\Log\LoggerInterface;

class EventManager implements EventManagerInterface 
{
    private SecurityManagerInterface $security;
    private ValidationServiceInterface $validator;
    private LoggerInterface $logger;
    private array $listeners = [];
    private array $config;

    public function __construct(
        SecurityManagerInterface $security,
        ValidationServiceInterface $validator,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function dispatch(string $eventName, array $payload = []): void 
    {
        $eventId = $this->generateEventId();

        try {
            // Validate event dispatch permission
            $this->security->validateOperation('event:dispatch', $eventName);

            // Validate event name and payload
            $this->validateEvent($eventName, $payload);

            // Log event dispatch
            $this->logger->info('Event dispatched', [
                'event_id' => $eventId,
                'event_name' => $eventName,
                'payload_size' => strlen(json_encode($payload))
            ]);

            // Get listeners
            $listeners = $this->getEventListeners($eventName);

            // Execute listeners
            foreach ($listeners as $listener) {
                $this->executeListener($eventId, $eventName, $listener, $payload);
            }

        } catch (\Exception $e) {
            $this->handleEventFailure($eventId, $eventName, $e);
            throw new EventException("Event dispatch failed: {$eventName}", 0, $e);
        }
    }

    public function subscribe(string $eventName, callable $listener, array $options = []): void 
    {
        try {
            // Validate subscription permission
            $this->security->validateOperation('event:subscribe', $eventName);

            // Validate listener
            $this->validateListener($listener);

            // Add listener with metadata
            $this->listeners[$eventName][] = [
                'callback' => $listener,
                'priority' => $options['priority'] ?? 0,
                'security' => $options['security'] ?? [],
                'validation' => $options['validation'] ?? []
            ];

            // Sort by priority
            $this->sortListeners($eventName);

        } catch (\Exception $e) {
            throw new EventException("Event subscription failed: {$eventName}", 0, $e);
        }
    }

    public function unsubscribe(string $eventName, callable $listener): void 
    {
        try {
            // Validate unsubscribe permission
            $this->security->validateOperation('event:unsubscribe', $eventName);

            // Remove listener
            if (isset($this->listeners[$eventName])) {
                $this->listeners[$eventName] = array_filter(
                    $this->listeners[$eventName],
                    fn($l) => $l['callback'] !== $listener
                );
            }

        } catch (\Exception $e) {
            throw new EventException("Event unsubscription failed: {$eventName}", 0, $e);
        }
    }

    private function validateEvent(string $eventName, array $payload): void 
    {
        // Validate event name format
        if (!preg_match('/^[a-zA-Z0-9\.:_-]+$/', $eventName)) {
            throw new EventException('Invalid event name format');
        }

        // Validate payload size
        if (strlen(json_encode($payload)) > $this->config['max_payload_size']) {
            throw new EventException('Payload size exceeds limit');
        }

        // Validate payload structure
        $this->validator->validateData($payload, 'event_payload');
    }

    private function validateListener(callable $listener): void 
    {
        if (!is_callable($listener)) {
            throw new EventException('Invalid listener format');
        }
    }

    private function executeListener(
        string $eventId, 
        string $eventName, 
        array $listener, 
        array $payload
    ): void {
        try {
            // Check listener security
            if (!empty($listener['security'])) {
                $this->security->validateContext($listener['security']);
            }

            // Validate payload against listener rules
            if (!empty($listener['validation'])) {
                $this->validator->validateData($payload, $listener['validation']);
            }

            // Execute with timeout
            $this->executeWithTimeout(
                fn() => $listener['callback']($payload, $eventName),
                $this->config['listener_timeout']
            );

        } catch (\Exception $e) {
            $this->handleListenerFailure($eventId, $eventName, $listener, $e);
        }
    }

    private function executeWithTimeout(callable $callback, int $timeout): mixed 
    {
        $result = null;
        $pid = pcntl_fork();

        if ($pid == -1) {
            throw new EventException('Could not fork process');
        } else if ($pid) {
            // Parent process
            $status = null;
            pcntl_waitpid($pid, $status, WNOHANG);
            
            $waited = 0;
            $interval = 100000; // 0.1 second
            
            while ($waited < $timeout) {
                $res = pcntl_waitpid($pid, $status, WNOHANG);
                if ($res == -1 || $res > 0) {
                    break;
                }
                usleep($interval);
                $waited += $interval;
            }
            
            if ($waited >= $timeout) {
                posix_kill($pid, SIGTERM);
                throw new EventException('Listener execution timed out');
            }
            
            return $result;
        } else {
            // Child process
            try {
                $result = $callback();
                exit(0);
            } catch (\Exception $e) {
                exit(1);
            }
        }
    }

    private function handleEventFailure(string $eventId, string $eventName, \Exception $e): void 
    {
        $this->logger->error('Event dispatch failed', [
            'event_id' => $eventId,
            'event_name' => $eventName,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    private function handleListenerFailure(
        string $eventId, 
        string $eventName, 
        array $listener,
        \Exception $e
    ): void {
        $this->logger->error('Event listener failed', [
            'event_id' => $eventId,
            'event_name' => $eventName,
            'listener' => get_class($listener['callback']),
            'error' => $e->getMessage()
        ]);
    }

    private function generateEventId(): string 
    {
        return uniqid('event_', true);
    }

    private function getEventListeners(string $eventName): array 
    {
        return $this->listeners[$eventName] ?? [];
    }

    private function sortListeners(string $eventName): void 
    {
        if (isset($this->listeners[$eventName])) {
            usort(
                $this->listeners[$eventName],
                fn($a, $b) => $b['priority'] - $a['priority']
            );
        }
    }

    private function getDefaultConfig(): array 
    {
        return [
            'max_payload_size' => 1048576, // 1MB
            'listener_timeout' => 30, // seconds
            'max_listeners' => 100,
            'priority_levels' => [-1, 0, 1]
        ];
    }
}
