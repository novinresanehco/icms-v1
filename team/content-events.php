<?php

namespace App\Core\Content\Events;

use App\Core\Content\Models\Content;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;

class ContentCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Content $content;

    public function __construct(Content $content)
    {
        $this->content = $content;
    }
}

class ContentUpdated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Content $content;
    public array $changedAttributes;

    public function __construct(Content $content, array $changedAttributes = [])
    {
        $this->content = $content;
        $this->changedAttributes = $changedAttributes;
    }
}

class ContentDeleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $contentId;
    public array $metadata;

    public function __construct(int $contentId, array $metadata = [])
    {
        $this->contentId = $contentId;
        $this->metadata = $metadata;
    }
}

class ContentStatusChanged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public Content $content;
    public string $oldStatus;
    public string $newStatus;

    public function __construct(Content $content, string $oldStatus, string $newStatus)
    {
        $this->content = $content;
        $this->oldStatus = $oldStatus;
        $this->newStatus = $newStatus;
    }
}

namespace App\Core\Content\Listeners;

use App\Core\Content\Events\{ContentCreated, ContentUpdated, ContentDeleted, ContentStatusChanged};
use App\Core\Cache\CacheManager;
use App\Core\Search\SearchIndexer;
use App\Core\Notification\NotificationService;
use Illuminate\Support\Facades\Log;

class ContentEventSubscriber
{
    private CacheManager $cache;
    private SearchIndexer $searchIndexer;
    private NotificationService $notificationService;

    public function __construct(
        CacheManager $cache,
        SearchIndexer $searchIndexer,
        NotificationService $notificationService
    ) {
        $this->cache = $cache;
        $this->searchIndexer = $searchIndexer;
        $this->notificationService = $notificationService;
    }

    /**
     * Handle content created events
     */
    public function handleContentCreated(ContentCreated $event): void
    {
        try {
            // Update search index
            $this->searchIndexer->indexContent($event->content);

            // Clear relevant caches
            $this->cache->tags(['content-list', 'site-map'])->flush();

            // Send notifications
            $this->notificationService->notifyAdmins('content.created', [
                'content_id' => $event->content->id,
                'title' => $event->content->title,
                'author' => $event->content->author->name
            ]);

            // Log the event
            Log::info('Content created successfully', [
                'content_id' => $event->content->id,
                'type' => $event->content->type
            ]);
        } catch (\Exception $e) {
            Log::error('Error handling content creation event', [
                'content_id' => $event->content->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle content updated events
     */
    public function handleContentUpdated(ContentUpdated $event): void
    {
        try {
            // Update search index
            $this->searchIndexer->updateContent($event->content);

            // Clear specific content cache
            $this->cache->tags(['content'])->forget("content.{$event->content->id}");

            // Clear related caches if necessary
            if ($this->shouldClearRelatedCaches($event->changedAttributes)) {
                $this->cache->tags(['content-list', 'site-map'])->flush();
            }

            // Send notifications for significant changes
            if ($this->isSignificantUpdate($event->changedAttributes)) {
                $this->notificationService->notifyRelevantUsers('content.updated', [
                    'content_id' => $event->content->id,
                    'title' => $event->content->title,
                    'changes' => $event->changedAttributes
                ]);
            }

            // Log the event
            Log::info('Content updated successfully', [
                'content_id' => $event->content->id,
                'changes' => $event->changedAttributes
            ]);
        } catch (\Exception $e) {
            Log::error('Error handling content update event', [
                'content_id' => $event->content->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle content deleted events
     */
    public function handleContentDeleted(ContentDeleted $event): void
    {
        try {
            // Remove from search index
            $this->searchIndexer->removeContent($event->contentId);

            // Clear all related caches
            $this->cache->tags([
                'content',
                'content-list',
                'site-map'
            ])->flush();

            // Send notifications
            $this->notificationService->notifyAdmins('content.deleted', [
                'content_id' => $event->contentId,
                'metadata' => $event->metadata
            ]);

            // Log the event
            Log::info('Content deleted successfully', [
                'content_id' => $event->contentId,
                'metadata' => $event->metadata
            ]);
        } catch (\Exception $e) {
            Log::error('Error handling content deletion event', [
                'content_id' => $event->contentId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle content status changed events
     */
    public function handleContentStatusChanged(ContentStatusChanged $event): void
    {
        try {
            // Update search index with new status
            $this->searchIndexer->updateContentStatus($event->content);

            // Clear relevant caches
            $this->cache->tags(['content'])->forget("content.{$event->content->id}");

            if ($event->newStatus === 'published') {
                $this->cache->tags(['content-list', 'site-map'])->flush();
            }

            // Send appropriate notifications
            $this->sendStatusChangeNotifications($event);

            // Log the event
            Log::info('Content status changed', [
                'content_id' => $event->content->id,
                'old_status' => $event->oldStatus,
                'new_status' => $event->newStatus
            ]);
        } catch (\Exception $e) {
            Log::error('Error handling content status change event', [
                'content_id' => $event->content->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Register the listeners for the subscriber
     */
    public function subscribe($events): array
    {
        return [
            ContentCreated::class => 'handleContentCreated',
            ContentUpdated::class => 'handleContentUpdated',
            ContentDeleted::class => 'handleContentDeleted',
            ContentStatusChanged::class => 'handleContentStatusChanged',
        ];
    }

    /**
     * Determine if cache clear is needed based on changed attributes
     */
    private function shouldClearRelatedCaches(array $changedAttributes): bool
    {
        $criticalAttributes = ['status', 'category_id', 'type', 'slug'];
        return !empty(array_intersect($criticalAttributes, array_keys($changedAttributes)));
    }

    /**
     * Determine if update is significant enough for notification
     */
    private function isSignificantUpdate(array $changedAttributes): bool
    {
        $significantAttributes = ['title', 'status', 'category_id', 'type'];
        return !empty(array_intersect($significantAttributes, array_keys($changedAttributes)));
    }

    /**
     * Send appropriate notifications for status changes
     */
    private function sendStatusChangeNotifications(ContentStatusChanged $event): void
    {
        $notificationData = [
            'content_id' => $event->content->id,
            'title' => $event->content->title,
            'old_status' => $event->oldStatus,
            'new_status' => $event->newStatus
        ];

        switch ($event->newStatus) {
            case 'published':
                $this->notificationService->notifySubscribers('content.published', $notificationData);
                break;
            case 'archived':
                $this->notificationService->notifyAdmins('content.archived', $notificationData);
                break;
            case 'draft':
                $this->notificationService->notifyAuthors('content.draft', $notificationData);
                break;
        }
    }
}
