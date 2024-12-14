<?php

namespace App\Core\Queue;

class MessageQueue
{
    private array $queues = [];
    private QueueStorage $storage;
    private ConsumerManager $consumerManager;
    private RetryPolicy $retryPolicy;

    public function publish(string $queueName, Message $message): bool
    {
        try {
            $queue = $this->getQueue($queueName);
            $messageId = $queue->enqueue($message);
            
            $this->storage->store($queueName, $messageId, $message);
            $this->notifyConsumers($queueName);
            
            return true;
        } catch (\Exception $e) {
            throw new QueueException("Failed to publish message: {$e->getMessage()}");
        }
    }

    public function consume(string $queueName, callable $callback, array $options = []): void
    {
        $queue = $this->getQueue($queueName);
        
        while (true) {
            $message = $queue->dequeue();
            
            if (!$message) {
                if ($options['wait'] ?? false) {
                    sleep(1);
                    continue;
                }
                break;
            }

            try {
                $result = $callback($message);
                $this->handleSuccess($queueName, $message);
            } catch (\Exception $e) {
                $this->handleFailure($queueName, $message, $e);
            }
        }
    }

    private function handleSuccess(string $queueName, Message $message): void
    {
        $this->storage->markAsProcessed($queueName, $message->getId());
    }

    private function handleFailure(string $queueName, Message $message, \Exception $e): void
    {
        if ($this->retryPolicy->shouldRetry($message)) {
            $this->retryMessage($queueName, $message);
        } else {
            $this->moveToDeadLetter($queueName, $message, $e);
        }
    }

    private function retryMessage(string $queueName, Message $message): void
    {
        $delay = $this->retryPolicy->getNextDelay($message);
        $message->incrementAttempts();
        
        $this->publish($queueName . '_retry', $message->withDelay($delay));
    }

    private function moveToDeadLetter(string $queueName, Message $message, \Exception $e): void
    {
        $this->publish(
            $queueName . '_dead',
            $message->withError($e->getMessage())
        );
    }

    private function getQueue(string $name): Queue
    {
        if (!isset($this->queues[$name])) {
            $this->queues[$name] = new Queue($name);
        }
        return $this->queues[$name];
    }

    private function notifyConsumers(string $queueName): void
    {
        $this->consumerManager->notifyNewMessage($queueName);
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

    public function enqueue(Message $message): string
    {
        $id = $message->getId() ?: uniqid('msg_', true);
        $this->messages[$id] = $message->withId($id);
        return $id;
    }

    public function dequeue(): ?Message
    {
        if (empty($this->messages)) {
            return null;
        }

        $id = array_key_first($this->messages);
        $message = $this->messages[$id];
        unset($this->messages[$id]);
        
        return $message;
    }

    public function peek(): ?Message
    {
        if (empty($this->messages)) {
            return null;
        }

        $id = array_key_first($this->messages);
        return $this->messages[$id];
    }

    public function count(): int
    {
        return count($this->messages);
    }

    public function clear(): void
    {
        $this->messages = [];
    }
}

class Message
{
    private string $id;
    private $payload;
    private array $attributes;
    private int $attempts;
    private ?int $delay;
    private ?string $error;

    public function __construct($payload, array $attributes = [])
    {
        $this->payload = $payload;
        $this->attributes = $attributes;
        $this->attempts = 0;
        $this->delay = null;
        $this->error = null;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getPayload()
    {
        return $this->payload;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $key, $default = null)
    {
        return $this->attributes[$key] ?? $default;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function incrementAttempts(): void
    {
        $this->attempts++;
    }

    public function withId(string $id): self
    {
        $clone = clone $this;
        $clone->id = $id;
        return $clone;
    }

    public function withDelay(int $delay): self
    {
        $clone = clone $this;
        $clone->delay = $delay;
        return $clone;
    }

    public function withError(string $error): self
    {
        $clone = clone $this;
        $clone->error = $error;
        return $clone;
    }
}

class RetryPolicy
{
    private int $maxAttempts;
    private array $delays;

    public function __construct(int $maxAttempts = 3, array $delays = [5, 15, 30])
    {
        $this->maxAttempts = $maxAttempts;
        $this->delays = $delays;
    }

    public function shouldRetry(Message $message): bool
    {
        return $message->getAttempts() < $this->maxAttempts;
    }

    public function getNextDelay(Message $message): int
    {
        $attempt = $message->getAttempts();
        return $this->delays[$attempt] ?? end($this->delays);
    }
}

class ConsumerManager
{
    private array $consumers = [];
    private array $activeConsumers = [];

    public function registerConsumer(string $queueName, callable $callback): string
    {
        $id = uniqid('consumer_', true);
        $this->consumers[$id] = [
            'queue' => $queueName,
            'callback' => $callback
        ];
        return $id;
    }

    public function startConsumer(string $consumerId): void
    {
        if (!isset($this->consumers[$consumerId])) {
            throw new QueueException("Consumer not found: {$consumerId}");
        }

        $this->activeConsumers[$consumerId] = true;
        $consumer = $this->consumers[$consumerId];
        
        while ($this->activeConsumers[$consumerId]) {
            try {
                ($consumer['callback'])();
            } catch (\Exception $e) {
                // Log error but continue consuming
                continue;
            }
        }
    }

    public function stopConsumer(string $consumerId): void
    {
        $this->activeConsumers[$consumerId] = false;
    }

    public function notifyNewMessage(string $queueName): void
    {
        foreach ($this->consumers as $id => $consumer) {
            if ($consumer['queue'] === $queueName && isset($this->activeConsumers[$id])) {
                // Notify consumer of new message
            }
        }
    }
}

class QueueStorage
{
    private $connection;

    public function store(string $queueName, string $messageId, Message $message): void
    {
        $this->connection->table('queue_messages')->insert([
            'queue' => $queueName,
            'message_id' => $messageId,
            'payload' => serialize($message),
            'created_at' => now()
        ]);
    }

    public function markAsProcessed(string $queueName, string $messageId): void
    {
        $this->connection->table('queue_messages')
            ->where('queue', $queueName)
            ->where('message_id', $messageId)
            ->update(['processed_at' => now()]);
    }

    public function getUnprocessedMessages(string $queueName): array
    {
        return $this->connection->table('queue_messages')
            ->where('queue', $queueName)
            ->whereNull('processed_at')
            ->get()
            ->map(fn($row) => unserialize($row->payload))
            ->toArray();
    }
}
