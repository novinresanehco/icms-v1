<?php

namespace App\Core\MessageBus;

class MessageBus
{
    private array $handlers = [];
    private array $middleware = [];
    private MessageSerializer $serializer;

    public function dispatch(Message $message): void
    {
        $pipeline = array_reduce(
            array_reverse($this->middleware),
            function ($next, $middleware) {
                return fn($message) => $middleware->handle($message, $next);
            },
            fn($message) => $this->handleMessage($message)
        );

        $pipeline($message);
    }

    private function handleMessage(Message $message): void
    {
        $messageClass = get_class($message);
        if (!isset($this->handlers[$messageClass])) {
            throw new MessageBusException("No handler found for message: $messageClass");
        }

        foreach ($this->handlers[$messageClass] as $handler) {
            $handler->handle($message);
        }
    }

    public function registerHandler(string $messageClass, MessageHandler $handler): void
    {
        $this->handlers[$messageClass][] = $handler;
    }

    public function addMiddleware(Middleware $middleware): void
    {
        $this->middleware[] = $middleware;
    }
}

abstract class Message
{
    private string $id;
    private \DateTime $createdAt;
    private array $metadata;

    public function __construct(array $metadata = [])
    {
        $this->id = uniqid('msg_', true);
        $this->createdAt = new \DateTime();
        $this->metadata = $metadata;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->createdAt;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}

interface MessageHandler
{
    public function handle(Message $message): void;
}

interface Middleware
{
    public function handle(Message $message, callable $next): void;
}

class LoggingMiddleware implements Middleware
{
    private LoggerInterface $logger;

    public function handle(Message $message, callable $next): void
    {
        $this->logger->info('Processing message', [
            'message_id' => $message->getId(),
            'message_type' => get_class($message)
        ]);

        try {
            $next($message);
            $this->logger->info('Message processed successfully', [
                'message_id' => $message->getId()
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Message processing failed', [
                'message_id' => $message->getId(),
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}

class RetryMiddleware implements Middleware
{
    private int $maxAttempts;
    private array $retryableExceptions;

    public function handle(Message $message, callable $next): void
    {
        $attempts = 0;

        while (true) {
            try {
                $next($message);
                break;
            } catch (\Exception $e) {
                if (!$this->shouldRetry($e, ++$attempts)) {
                    throw $e;
                }
                sleep(pow(2, $attempts)); // Exponential backoff
            }
        }
    }

    private function shouldRetry(\Exception $e, int $attempts): bool
    {
        if ($attempts >= $this->maxAttempts) {
            return false;
        }

        foreach ($this->retryableExceptions as $exceptionClass) {
            if ($e instanceof $exceptionClass) {
                return true;
            }
        }

        return false;
    }
}

class TransactionMiddleware implements Middleware
{
    private $connection;

    public function handle(Message $message, callable $next): void
    {
        $this->connection->beginTransaction();

        try {
            $next($message);
            $this->connection->commit();
        } catch (\Exception $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }
}

class MessageSerializer
{
    public function serialize(Message $message): string
    {
        return serialize([
            'class' => get_class($message),
            'id' => $message->getId(),
            'created_at' => $message->getCreatedAt(),
            'metadata' => $message->getMetadata(),
            'data' => $this->extractMessageData($message)
        ]);
    }

    public function unserialize(string $serialized): Message
    {
        $data = unserialize($serialized);
        $class = $data['class'];
        $message = new $class($data['metadata']);
        $this->hydrateMessageData($message, $data['data']);
        return $message;
    }

    private function extractMessageData(Message $message): array
    {
        $reflection = new \ReflectionClass($message);
        $properties = $reflection->getProperties();
        $data = [];

        foreach ($properties as $property) {
            $property->setAccessible(true);
            $data[$property->getName()] = $property->getValue($message);
        }

        return $data;
    }

    private function hydrateMessageData(Message $message, array $data): void
    {
        $reflection = new \ReflectionClass($message);
        foreach ($data as $property => $value) {
            if ($reflection->hasProperty($property)) {
                $property = $reflection->getProperty($property);
                $property->setAccessible(true);
                $property->setValue($message, $value);
            }
        }
    }
}

class MessageBusException extends \Exception {}
