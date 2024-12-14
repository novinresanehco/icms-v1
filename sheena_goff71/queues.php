<?php

namespace App\Core\Queue;

class QueueManager
{
    private array $queues = [];
    private MessageValidator $validator;
    private QueueMetrics $metrics;
    
    public function __construct(MessageValidator $validator, QueueMetrics $metrics)
    {
        $this->validator = $validator;
        $this->metrics = $metrics;
    }

    public function enqueue(string $queueName, Message $message): void
    {
        $this->validator->validate($message);
        $queue = $this->getQueue($queueName);
        $queue->push($message);
        $this->metrics->recordEnqueue($queueName);
    }

    public function dequeue(string $queueName): ?Message
    {
        $queue = $this->getQueue($queueName);
        $message = $queue->pop();
        
        if ($message) {
            $this->metrics->recordDequeue($queueName);
        }
        
        return $message;
    }

    private function getQueue(string $name): Queue
    {
        if (!isset($this->queues[$name])) {
            $this->queues[$name] = new Queue($name);
        }
        return $this->queues[$name];
    }
}

class Queue
{
    private string $name;
    private array $messages = [];
    
    public function __construct(string $name)
    {
        $this->name = $name;
    }
    
    public function push(Message $message): void
    {
        $this->messages[] = $message;
    }
    
    public function pop(): ?Message
    {
        return array_shift($this->messages);
    }
    
    public function size(): int
    {
        return count($this->messages);
    }
}

class Message
{
    public function __construct(
        private string $id,
        private string $type,
        private array $payload,
        private array $metadata = []
    ) {}

    public function getId(): string 
    {
        return $this->id;
    }
    
    public function getType(): string
    {
        return $this->type;
    }
    
    public function getPayload(): array
    {
        return $this->payload;
    }
    
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}

class MessageValidator
{
    public function validate(Message $message): void
    {
        if (empty($message->getId())) {
            throw new QueueException('Message ID is required');
        }

        if (empty($message->getType())) {
            throw new QueueException('Message type is required');
        }

        if (empty($message->getPayload())) {
            throw new QueueException('Message payload is required');
        }
    }
}

class QueueMetrics
{
    private MetricsCollector $collector;
    
    public function __construct(MetricsCollector $collector)
    {
        $this->collector = $collector;
    }
    
    public function recordEnqueue(string $queueName): void
    {
        $this->collector->increment("queue.{$queueName}.enqueued");
        $this->collector->gauge("queue.{$queueName}.size", $this->getQueueSize($queueName));
    }
    
    public function recordDequeue(string $queueName): void
    {
        $this->collector->increment("queue.{$queueName}.dequeued");
        $this->collector->gauge("queue.{$queueName}.size", $this->getQueueSize($queueName));
    }
    
    private function getQueueSize(string $queueName): int
    {
        // Implementation to get queue size
        return 0;
    }
}

class QueueException extends \Exception {}
