<?php

namespace App\Core\Event\Services;

use App\Core\Event\Models\Event;

class EventDispatcher
{
    public function dispatch(Event $event): void
    {
        if (!$event->canProcess()) {
            return;
        }

        if ($event->scheduled_at) {
            $this->dispatchLater($event);
            return;
        }

        dispatch(function () use ($event) {
            $this->process($event);
        })->onQueue('events');
    }

    protected function dispatchLater(Event $event): void
    {
        dispatch(function () use ($event) {
            $this->process($event);
        })
        ->delay($event->scheduled_at)
        ->onQueue('events');
    }

    protected function process(Event $event): void
    {
        try {
            $event->markAsProcessing();
            
            $processor = $this->getProcessor($event->type);
            $result = $processor->process($event->data);
            
            $event->markAsCompleted($result);
        } catch (\Exception $e) {
            $this->handleProcessingFailure($event, $e);
        }
    }

    protected function handleProcessingFailure(Event $event, \Exception $e): void
    {
        $event->markAsFailed($e->getMessage());

        if ($event->shouldRetry()) {
            $this->dispatch($event);
        }

        logger()->error('Event processing failed', [
            'event_id' => $event->id,
            'type' => $event->type,
            'error' => $e->getMessage()
        ]);
    }

    protected function getProcessor(string $type): EventProcessor
    {
        return match($type) {
            'notification' => new NotificationEventProcessor(),
            'email' => new EmailEventProcessor(),
            'sync' => new SyncEventProcessor(),
            default => throw new EventException("Unknown event type: {$type}")
        };
    }
}
