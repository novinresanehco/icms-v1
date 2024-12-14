<?php

namespace App\Core\Event;

use Illuminate\Support\Facades\{DB, Cache, Queue};
use App\Core\Security\SecurityManager;
use App\Core\Services\{ValidationService, AuditService};
use App\Core\Interfaces\EventManagerInterface;
use App\Core\Exceptions\{EventException, ValidationException};

class EventManager implements EventManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private AuditService $audit;
    private array $config;
    private array $handlers = [];
    private array $messageQueue = [];

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        AuditService $audit,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function dispatch(string $event, array $payload = []): bool
    {
        return $this->security->executeSecureOperation(
            fn() => $this->processEvent($event, $payload),
            new SecurityContext('event.dispatch', ['event' => $event])
        );
    }

    public function subscribe(string $event, callable $handler, int $priority = 0): void
    {
        $this->security->executeSecureOperation(
            fn() => $this->registerHandler($event, $handler, $priority),
            new SecurityContext('event.subscribe', ['event' => $event])
        );
    }

    public function broadcast(string $channel, array $message): bool
    {
        return $this->security->executeSecureOperation(
            fn() => $this->processBroadcast($channel, $message),
            new SecurityContext('event.broadcast', ['channel' => $channel])
        );
    }

    protected function processEvent(string $event, array $payload): bool
    {
        try {
            $this->validateEvent($event);
            $this->validatePayload($payload);
            
            $processedPayload = $this->processPayload($payload);
            $handlers = $this->getEventHandlers($event);
            
            if (empty($handlers)) {
                $this->queueEvent($event, $processedPayload);
                return true;
            }

            DB::beginTransaction();
            try {
                foreach ($handlers as $handler) {
                    $this->executeHandler($handler, $processedPayload);
                }
                
                DB::commit();
                $this->audit->logEventProcessed($event, $processedPayload);
                return true;

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            $this->handleEventFailure($event, $e);
            throw new EventException('Event processing failed: ' . $e->getMessage());
        }
    }

    protected function registerHandler(string $event, callable $handler, int $priority): void
    {
        try {
            $this->validateHandler($handler);
            
            if (!isset($this->handlers[$event])) {
                $this->handlers[$event] = [];
            }
            
            $this->handlers[$event][] = [
                'handler' => $handler,
                'priority' => $priority
            ];
            
            usort($this->handlers[$event], fn($a, $b) => $b['priority'] - $a['priority']);
            
            $this->audit->logHandlerRegistered($event, get_class($handler));

        } catch (\Exception $e) {
            $this->handleRegistrationFailure($event, $e);
            throw new EventException('Handler registration failed: ' . $e->getMessage());
        }
    }

    protected function processBroadcast(string $channel, array $message): bool
    {
        try {
            $this->validateChannel($channel);
            $this->validateMessage($message);
            
            $processedMessage = $this->processMessage($message);
            $this->enqueueBroadcast($channel, $processedMessage);
            
            return true;

        } catch (\Exception $e) {
            $this->handleBroadcastFailure($channel, $e);
            throw new EventException('Broadcast failed: ' . $e->getMessage());
        }
    }

    protected function validateEvent(string $event): void
    {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9\._]+$/', $event)) {
            throw new ValidationException('Invalid event name format');
        }

        if (!$this->validator->validateEvent($event)) {
            throw new ValidationException('Invalid event');
        }
    }

    protected function validatePayload(array $payload): void
    {
        if (!$this->validator->validateEventPayload($payload)) {
            throw new ValidationException('Invalid event payload');
        }

        if ($this->exceedsPayloadLimit($payload)) {
            throw new ValidationException('Payload size exceeds limit');
        }
    }

    protected function validateHandler(callable $handler): void
    {
        if (!is_callable($handler)) {
            throw new ValidationException('Invalid event handler');
        }
    }

    protected function validateChannel(string $channel): void
    {
        if (!$this->validator->validateBroadcastChannel($channel)) {
            throw new ValidationException('Invalid broadcast channel');
        }
    }

    protected function validateMessage(array $message): void
    {
        if (!$this->validator->validateBroadcastMessage($message)) {
            throw new ValidationException('Invalid broadcast message');
        }
    }

    protected function processPayload(array $payload): array
    {
        return array_map(function($value) {
            if (is_array($value)) {
                return $this->processPayload($value);
            }
            return $this->sanitizePayloadValue($value);
        }, $payload);
    }

    protected function sanitizePayloadValue($value): mixed
    {
        if (is_string($value)) {
            return strip_tags($value);
        }
        return $value;
    }

    protected function processMessage(array $message): array
    {
        return [
            'data' => $this->processPayload($message),
            'metadata' => [
                'timestamp' => time(),
                'trace_id' => $this->generateTraceId(),
                'security_context' => $this->getSecurityContext()
            ]
        ];
    }

    protected function queueEvent(string $event, array $payload): void
    {
        Queue::push(new ProcessEventJob($event, $payload));
    }

    protected function enqueueBroadcast(string $channel, array $message): void
    {
        Queue::push(new BroadcastMessageJob($channel, $message));
    }

    protected function executeHandler(array $handlerData, array $payload): void
    {
        try {
            $startTime = microtime(true);
            $handler = $handlerData['handler'];
            
            $result = $handler($payload);
            $executionTime = microtime(true) - $startTime;
            
            $this->validateHandlerResult($result);
            $this->recordHandlerMetrics($handler, $executionTime);

        } catch (\Exception $e) {
            $this->handleExecutionFailure($handler, $e);
            throw $e;
        }
    }

    protected function exceedsPayloadLimit(array $payload): bool
    {
        return strlen(serialize($payload)) > $this->config['max_payload_size'];
    }

    protected function generateTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
