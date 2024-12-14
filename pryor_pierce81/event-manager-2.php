<?php

namespace App\Core\Event;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Exception\EventException;
use Psr\Log\LoggerInterface;

class EventManager implements EventManagerInterface
{
    private SecurityManagerInterface $security;
    private LoggerInterface $logger;
    private array $config;
    private array $handlers = [];

    public function __construct(
        SecurityManagerInterface $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function dispatchEvent(Event $event): void
    {
        $eventId = $this->generateEventId();

        try {
            DB::beginTransaction();

            $this->security->validateSecureOperation('event:dispatch', [
                'event_type' => $event->getType()
            ]);

            $this->validateEvent($event);
            $this->validateEventHandlers($event->getType());

            $this->processEvent($eventId, $event);
            $this->logEventDispatch($eventId, $event);

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleEventFailure($eventId, $event, $e);
            throw new EventException('Event dispatch failed', 0, $e);
        }
    }

    public function registerHandler(string $eventType, EventHandler $handler): void
    {
        try {
            $this->security->validateSecureOperation('event:register', [
                'event_type' => $eventType
            ]);

            $this->validateEventType($eventType);
            $this->validateHandler($handler);

            $this->handlers[$eventType][] = $handler;
            $this->logHandlerRegistration($eventType, $handler);

        } catch (\Exception $e) {
            $this->handleRegistrationFailure($eventType, $handler, $e);
            throw new EventException('Handler registration failed', 0, $e);
        }
    }

    private function validateEvent(Event $event): void
    {
        if (!$event->isValid()) {
            throw new EventException('Invalid event structure');
        }

        if (!$this->isEventTypeAllowed($event->getType())) {
            throw new EventException('Event type not allowed');
        }

        foreach ($this->config['validation_rules'] as $rule) {
            if (!$this->validateEventRule($event, $rule)) {
                throw new EventException("Event validation failed: {$rule}");
            }
        }
    }

    private function processEvent(string $eventId, Event $event): void
    {
        $handlers = $this->getEventHandlers($event->getType());
        
        foreach ($handlers as $handler) {
            try {
                $this->executeHandler($handler, $event);
            } catch (\Exception $e) {
                $this->handleHandlerFailure($eventId, $handler, $e);
                if ($this->config['fail_fast']) {
                    throw $e;
                }
            }
        }
    }

    private function executeHandler(EventHandler $handler, Event $event): void
    {
        $context = $this->createHandlerContext($handler, $event);
        $result = $handler->handle($event, $context);
        
        $this->validateHandlerResult($result);
        $this->logHandlerExecution($handler, $event, $result);
    }

    private function validateHandler(EventHandler $handler): void
    {
        if (!$handler instanceof EventHandlerInterface) {
            throw new EventException('Invalid handler implementation');
        }

        if (!$this->validateHandlerSecurity($handler)) {
            throw new EventException('Handler security validation failed');
        }
    }

    private function handleEventFailure(string $id, Event $event, \Exception $e): void
    {
        $this->logger->error('Event processing failed', [
            'event_id' => $id,
            'event_type' => $event->getType(),
            'error' => $e->getMessage()
        ]);

        if ($this->config['alert_on_failure']) {
            $this->notifyEventFailure($id, $event, $e);
        }
    }

    private function getDefaultConfig(): array
    {
        return [
            'allowed_event_types' => [
                'system',
                'security',
                'audit',
                'user',
                'content'
            ],
            'validation_rules' => [
                'structure',
                'payload',
                'security'
            ],
            'fail_fast' => true,
            'alert_on_failure' => true,
            'max_handlers' => 10
        ];
    }
}
