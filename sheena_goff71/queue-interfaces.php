<?php

namespace App\Core\Queue;

interface QueueManagerInterface 
{
    /**
     * Process a queue item with full protection and monitoring
     *
     * @param QueueItem $item The queue item to process
     * @return ProcessResult The processing result
     * @throws QueueProcessingException If processing fails
     */
    public function processQueueItem(QueueItem $item): ProcessResult;
}

interface QueueItem
{
    /**
     * Process the queue item
     * 
     * @return mixed The processing result
     * @throws ProcessingException If processing fails
     */
    public function process(): mixed;

    /**
     * Get the queue item's state
     *
     * @return array The current state
     */
    public function getState(): array;

    /**
     * Get processing constraints
     *
     * @return array Processing constraints
     */
    public function getConstraints(): array;
}

class ProcessResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $trackingId,
        public readonly mixed $result
    ) {}
}

class QueueProcessingException extends \RuntimeException
{
    public function __construct(
        string $message = '',
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
