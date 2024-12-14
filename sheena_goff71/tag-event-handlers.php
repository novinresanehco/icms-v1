<?php

namespace App\Core\Tag\Repository\EventHandlers;

use App\Core\Tag\Events\{
    TagCreated,
    TagUpdated,
    TagDeleted,
    TagRestored,
    TagForceDeleted
};
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use App\Core\Tag\Services\{
    TagCacheService,
    TagAnalyticsService,
    TagNotificationService
};

class TagEventSubscriber
{
    /**
     * @var TagCacheService
     */
    protected TagCacheService $cacheService;

    /**
     * @var TagAnalyticsService
     */
    protected TagAnalyticsService $analyticsService;

    /**
     * @var TagNotificationService
     */
    protected TagNotificationService $notificationService;

    public function __construct(
        TagCacheService $cacheService,
        TagAnalyticsService $analyticsService,
        TagNotificationService $notificationService
    ) {
        $this->cacheService = $cacheService;
        $this->analyticsService = $analyticsService;
        $this->notificationService = $notificationService;
    }

    /**
     * Handle tag created events.
     */
    public function handleTagCreated(TagCreated $event): void
    {
        try {
            // Update analytics
            $this->analyticsService->recordTagCreation($event->tag);

            // Notify relevant users
            $this->notificationService->notifyTagCreated($event->tag);

            // Log the event
            Log::info('Tag created', [
                'tag_id' => $event->tag->id,
                'tag_name' => $event->tag->name
            ]);
        } catch (\Exception $e) {
            Log::error('Error handling tag created event', [
                'tag_id' => $event->tag->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle tag updated events.
     */
    public function handleTagUpdated(TagUpdated $event): void
    {
        try {
            // Update analytics
            $this->analyticsService->recordTagUpdate($event->tag);

            // Clear relevant caches
            $this->cacheService->clearTagCache($event->tag->id);

            // Notify relevant users
            $this->notificationService->notifyTagUpdated($event->tag);

            // Log the event
            Log::info('Tag updated', [
                'tag_id' => $event->tag->id,
                'changes' => $event->tag->getChanges()
            ]);
        } catch (\Exception $e) {
            Log::error('Error handling tag updated event', [
                'tag_id' => $event->tag->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Register the listeners for the subscriber.
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            TagCreated::class => 'handleTagCreated',
            TagUpdated::class => 'handleTagUpdated',
            TagDeleted::class => 'handleTagDeleted',
            TagRestored::class => 'handleTagRestored',
            TagForceDeleted::class => 'handleTagForceDeleted',
        ];
    }

    /**
     * Handle tag deleted events.
     */
    protected function handleTagDeleted(TagDeleted $event): void
    {
        try {
            // Update analytics
            $this->analyticsService->recordTagDeletion($event->tag);

            // Clear all related caches
            $this->cacheService->clearTagCache($event->tag->id);

            // Notify relevant users
            $this->notificationService->notifyTagDeleted($event->tag);

            // Log the event
            Log::info('Tag deleted', [
                'tag_id' => $event->tag->id,
                'tag_name' => $event->tag->name
            ]);
        } catch (\Exception $e) {
            Log::error('Error handling tag deleted event', [
                'tag_id' => $event->tag->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle tag restored events.
     */
    protected function handleTagRestored(TagRestored $event): void
    {
        try {
            // Update analytics
            $this->analyticsService->recordTagRestoration($event->tag);

            // Clear relevant caches
            $this->cacheService->clearTagCache($event->tag->id);

            // Notify relevant users
            $this->notificationService->notifyTagRestored($event->tag);

            // Log the event
            Log::info('Tag restored', [
                'tag_id' => $event->tag->id,
                'tag_name' => $event->tag->name
            ]);
        } catch (\Exception $e) {
            Log::error('Error handling tag restored event', [
                'tag_id' => $event->tag->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle tag force deleted events.
     */
    protected function handleTagForceDeleted(TagForceDeleted $event): void
    {
        try {
            // Update analytics
            $this->analyticsService->recordTagForceDeletion($event->tag);

            // Clear all related caches
            $this->cacheService->clearTagCache($event->tag->id);

            // Notify relevant users
            $this->notificationService->notifyTagForceDeleted($event->tag);

            // Log the event
            Log::info('Tag force deleted', [
                'tag_id' => $event->tag->id,
                'tag_name' => $event->tag->name
            ]);
        } catch (\Exception $e) {
            Log::error('Error handling tag force deleted event', [
                'tag_id' => $event->tag->id,
                'error' => $e->getMessage()
            ]);
        }
    }
}
