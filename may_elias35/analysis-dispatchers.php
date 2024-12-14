<?php

namespace App\Core\Audit\Dispatchers;

class EventDispatcher
{
    private array $listeners = [];
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function dispatch(Event $event): void
    {
        $eventName = get_class($event);
        
        if (!isset($this->listeners[$eventName])) {
            return;
        }

        foreach ($this->listeners[$eventName] as $listener) {
            try {
                $listener($event);
            } catch (\Exception $e) {
                $this->logger->error('Event listener failed', [
                    'event' => $eventName,
                    'listener' => get_class($listener),
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    public function addListener(string $eventName, callable $listener): void
    {
        if (!isset($this->listeners[$eventName])) {
            $this->listeners[$eventName] = [];
        }
        
        $this->listeners[$eventName][] = $listener;
    }

    public function removeListener(string $eventName, callable $listener): void
    {
        if (!isset($this->listeners[$eventName])) {
            return;
        }

        $this->listeners[$eventName] = array_filter(
            $this->listeners[$eventName],
            fn($existing) => $existing !== $listener
        );
    }
}

class TaskDispatcher
{
    private QueueInterface $queue;
    private array $handlers;
    private LoggerInterface $logger;

    public function __construct(
        QueueInterface $queue,
        array $handlers,
        LoggerInterface $logger
    ) {
        $this->queue = $queue;
        $this->handlers = $handlers;
        $this->logger = $logger;
    }

    public function dispatch(Task $task): void
    {
        $handler = $this->getHandler($task);
        
        try {
            if ($task->isAsync()) {
                $this->queue->push($task);
            } else {
                $handler->handle($task);
            }
            
            $this->logger->info('Task dispatched', [
                'task' => get_class($task),
                'async' => $task->isAsync()
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Task dispatch failed', [
                'task' => get_class($task),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function getHandler(Task $task): TaskHandler
    {
        $taskClass = get_class($task);
        
        if (!isset($this->handlers[$taskClass])) {
            throw new \InvalidArgumentException("No handler found for task: {$taskClass}");
        }
        
        return $this->handlers[$taskClass];
    }
}

class NotificationDispatcher
{
    private array $channels;
    private NotificationFormatter $formatter;
    private LoggerInterface $logger;

    public function __construct(
        array $channels,
        NotificationFormatter $formatter,
        LoggerInterface $logger
    ) {
        $this->channels = $channels;
        $this->formatter = $formatter;
        $this->logger = $logger;
    }

    public function send(Notification $notification): void
    {
        $formattedNotification = $this->formatter->format($notification);
        
        foreach ($notification->getChannels() as $channelName) {
            if (!isset($this->channels[$channelName])) {
                continue;
            }

            try {
                $this->channels[$channelName]->send($formattedNotification);
                
                $this->logger->info('Notification sent', [
                    'channel' => $channelName,
                    'notification' => get_class($notification)
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Notification failed', [
                    'channel' => $channelName,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
}
