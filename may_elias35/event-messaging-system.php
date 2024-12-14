<?php

namespace App\Core\Events;

class EventDispatcher implements EventDispatcherInterface
{
    private SecurityManager $security;
    private ListenerRegistry $listeners;
    private QueueManager $queue;
    private AuditLogger $logger;

    public function dispatch(Event $event): void
    {
        $this->security->executeCriticalOperation(
            new DispatchEventOperation(
                $event,
                $this->listeners,
                $this->queue,
                $this->logger
            )
        );
    }

    public function addListener(string $event, callable $listener, int $priority = 0): void
    {
        $this->security->validateListener($listener);
        $this->listeners->register($event, $listener, $priority);
    }
}

class MessageBroker implements MessageBrokerInterface
{
    private SecurityManager $security;
    private QueueManager $queue;
    private MessageValidator $validator;
    private RetryManager $retry;

    public function publish(Message $message, string $queue): void
    {
        $this->security->executeCriticalOperation(
            new PublishMessageOperation(
                $message,
                $queue,
                $this->validator,
                $this->queue
            )
        );
    }

    public function subscribe(string $queue, callable $handler): void
    {
        $this->security->validateHandler($handler);
        $this->queue->subscribe($queue, $handler);
    }
}

class DispatchEventOperation implements CriticalOperation
{
    private Event $event;
    private ListenerRegistry $listeners;
    private QueueManager $queue;
    private AuditLogger $logger;

    public function execute(): void
    {
        $listeners = $this->listeners->getForEvent(get_class($this->event));
        
        foreach ($listeners as $listener) {
            try {
                $this->executeListener($listener);
            } catch (\Exception $e) {
                $this->handleFailure($listener, $e);
            }
        }

        if ($this->event->shouldQueue()) {
            $this->queueEvent();
        }
    }

    private function executeListener(EventListener $listener): void
    {
        $startTime = microtime(true);
        
        try {
            $listener->handle($this->event);
            $this->logSuccess($listener, $startTime);
        } catch (\Exception $e) {
            $this->logFailure($listener, $e, $startTime);
            throw $e;
        }
    }

    private function queueEvent(): void
    {
        $this->queue->push(new QueuedEvent(
            $this->event,
            EventPriority::HIGH
        ));
    }
}

class PublishMessageOperation implements CriticalOperation
{
    private Message $message;
    private string $queue;
    private MessageValidator $validator;
    private QueueManager $queueManager;

    public function execute(): void
    {
        $this->validator->validate($this->message);
        
        $this->queueManager->push(
            $this->queue,
            $this->message,
            $this->getOptions()
        );
    }

    private function getOptions(): array
    {
        return [
            'priority' => $this->message->getPriority(),
            'retry' => [
                'attempts' => 3,
                'delay' => 5000,
                'multiplier' => 2
            ],
            'expiration' => 3600000,
            'persistent' => true
        ];
    }
}

class QueueManager
{
    private ConnectionManager $connection;
    private MessageSerializer $serializer;
    private RetryManager $retry;
    private array $queues = [];

    public function push(string $queue, $message, array $options = []): void
    {
        $serialized = $this->serializer->serialize($message);
        
        $channel = $this->connection->channel();
        $channel->queue_declare($queue, false, true, false, false);
        
        $channel->basic_publish(
            new AMQPMessage(
                $serialized,
                array_merge(['delivery_mode' => 2], $options)
            ),
            '',
            $queue
        );
    }

    public function subscribe(string $queue, callable $handler): void
    {
        $channel = $this->connection->channel();
        $channel->queue_declare($queue, false, true, false, false);
        
        $channel->basic_qos(null, 1, null);
        $channel->basic_consume(
            $queue,
            '',
            false,
            false,
            false,
            false,
            $this->createMessageHandler($handler)
        );
    }

    private function createMessageHandler(callable $handler): callable
    {
        return function($msg) use ($handler) {
            try {
                $message = $this->serializer->deserialize($msg->body);
                $handler($message);
                $msg->ack();
            } catch (\Exception $e) {
                if ($this->retry->shouldRetry($msg)) {
                    $msg->nack(false, true);
                } else {
                    $msg->reject(false);
                }
            }
        };
    }
}

class RetryManager
{
    private array $retryConfig;
    private AuditLogger $logger;

    public function shouldRetry(AMQPMessage $message): bool
    {
        $headers = $message->get('application_headers');
        $attempts = $headers->getNativeData()['retry_count'] ?? 0;
        
        return $attempts < $this->retryConfig['max_attempts'];
    }

    public function handleRetry(AMQPMessage $message): void
    {
        $headers = $message->get('application_headers');
        $attempts = $headers->getNativeData()['retry_count'] ?? 0;
        
        $headers->set('retry_count', $attempts + 1);
        $this->logRetry($message, $attempts + 1);
    }
}
