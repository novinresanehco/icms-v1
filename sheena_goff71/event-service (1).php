<?php

namespace App\Core\Event\Services;

use App\Core\Event\Models\Event;
use App\Core\Event\Repositories\EventRepository;
use Illuminate\Support\Facades\DB;

class EventService
{
    public function __construct(
        private EventRepository $repository,
        private EventValidator $validator,
        private EventDispatcher $dispatcher
    ) {}

    public function create(array $data): Event
    {
        $this->validator->validateCreate($data);

        return DB::transaction(function () use ($data) {
            $event = $this->repository->create([
                'name' => $data['name'],
                'type' => $data['type'],
                'data' => $data['data'],
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'status' => 'pending'
            ]);

            $this->dispatcher->dispatch($event);
            return $event;
        });
    }

    public function process(Event $event): void
    {
        if (!$event->canProcess()) {
            throw new EventException("Event cannot be processed in current status");
        }

        try {
            $event->markAsProcessing();
            $processor = $this->getProcessor($event->type);
            $result = $processor->process($event);
            $event->markAsCompleted($result);
        } catch (\Exception $e) {
            $this->handleProcessingFailure($event, $e);
        }
    }

    public function retry(Event $event): bool
    {
        if (!$event->canRetry()) {
            throw new EventException("Event cannot be retried");
        }

        $event->incrementAttempts();
        $event->updateStatus('pending');
        $this->dispatcher->dispatch($event);

        return true;
    }

    public function cancel(Event $event): bool
    {
        if (!$event->canCancel()) {
            throw new EventException("Event cannot be cancelled");
        }

        return $event->updateStatus('cancelled');
    }

    public function getScheduledEvents(): Collection
    {
        return $this->repository->getScheduledEvents();
    }

    public function getPendingEvents(): Collection
    {
        return $this->repository->getPendingEvents();
    }

    public function getFailedEvents(): Collection
    {
        return $this->repository->getFailedEvents();
    }

    public function getStats(): array
    {
        return $this->repository->getStats();
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

    protected function handleProcessingFailure(Event $event, \Exception $e): void
    {
        $event->markAsFailed($e->getMessage());

        if ($event->shouldRetry()) {
            $this->retry($event);
        }
    }
}
