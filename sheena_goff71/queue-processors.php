<?php

namespace App\Core\Queue\Processing;

class QueueProcessor
{
    private QueueManager $queueManager;
    private HandlerRegistry $handlerRegistry;
    private ErrorHandler $errorHandler;
    
    public function __construct(
        QueueManager $queueManager,
        HandlerRegistry $handlerRegistry,
        ErrorHandler $errorHandler
    ) {
        $this->queueManager = $queueManager;
        $this->handlerRegistry = $handlerRegistry;
        $this->errorHandler = $errorHandler;
    }
    
    public function process(string $queueName): void
    {
        while ($message = $this->queueManager->dequeue($queueName)) {
            try {
                $handler = $this->handlerRegistry->getHandler($message->getType());
                $handler->handle($message);
            } catch (\Exception $e) {
                $this->errorHandler->handle($e, $message);
            }
        }
    }
}

class HandlerRegistry
{
    private array $handlers = [];
    
    public function register(string $messageType, MessageHandler $handler): void
    {
        $this->handlers[$messageType] = $handler;
    }
    
    public function getHandler(string $messageType): MessageHandler
    {
        if (!isset($this->handlers[$messageType])) {
            throw new QueueException("No handler registered for message type: {$messageType}");
        }
        return $this->handlers[$messageType];
    }
}

interface MessageHandler
{
    public function handle(Message $message): void;
}

class ErrorHandler
{
    private LoggerInterface $logger;
    private array $retryPolicies;
    
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }
    
    public function handle(\Exception $exception, Message $message): void
    {
        $this->logger->error('Queue processing error', [
            'message_id' => $message->getId(),
            'type' => $message->getType(),
            'error' => $exception->getMessage()
        ]);

        $policy = $this->getRetryPolicy($message->getType());
        if ($policy->shouldRetry($message)) {
            $policy->retry($message);
        }
    }
    
    private function getRetryPolicy(string $messageType): RetryPolicy
    {
        return $this->retryPolicies[$messageType] ?? new DefaultRetryPolicy();
    }
}

interface RetryPolicy
{
    public function shouldRetry(Message $message): bool;
    public function retry(Message $message): void;
}

class DefaultRetryPolicy implements RetryPolicy
{
    public function shouldRetry(Message $message): bool
    {
        $attempts = $message->getMetadata()['retry_count'] ?? 0;
        return $attempts < 3;
    }
    
    public function retry(Message $message): void
    {
        // Implementation for retry logic
    }
}
