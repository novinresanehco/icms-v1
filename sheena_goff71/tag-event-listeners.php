<?php

namespace App\Core\Tag\Listeners;

use App\Core\Tag\Events\{TagCreated, TagUpdated, TagsAttached, TagsMerged};
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Core\Search\Services\SearchIndexer;
use App\Core\ActivityLog\Services\ActivityLogger;

class TagEventSubscriber
{
    /**
     * @var SearchIndexer
     */
    protected SearchIndexer $searchIndexer;

    /**
     * @var ActivityLogger
     */
    protected ActivityLogger $activityLogger;

    /**
     * @param SearchIndexer $searchIndexer
     * @param ActivityLogger $activityLogger
     */
    public function __construct(
        SearchIndexer $searchIndexer,
        ActivityLogger $activityLogger
    ) {
        $this->searchIndexer = $searchIndexer;
        $this->activityLogger = $activityLogger;
    }

    /**
     * Handle tag created events.
     *
     * @param TagCreated $event
     * @return void
     */
    public function handleTagCreated(TagCreated $event): void
    {
        try {
            // Index the new tag for search
            $this->searchIndexer->indexTag($event->tag);

            // Log the activity
            $this->activityLogger->log(
                'tag.created',
                'Tag created: ' . $event->tag->name,
                $event->tag
            );

            // Clear relevant caches
            $this->clearTagCaches();
        } catch (\Exception $e) {
            Log::error('Failed to handle tag created event', [
                'tag' => $event->tag->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle tag updated events.
     *
     * @param TagUpdated $event
     * @return void
     */
    public function handleTagUpdated(TagUpdated $event): void
    {
        try {
            // Update search index
            $this->searchIndexer->updateTag($event->tag);

            // Log the activity
            $this->activityLogger->log(
                'tag.updated',
                'Tag updated: ' . $event->tag->name,
                $event->tag
            );

            // Clear relevant caches
            $this->clearTagCaches();
        } catch (\Exception $e) {
            Log::error('Failed to handle tag updated event', [
                'tag' => $event->tag->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle tags attached events.
     *
     * @param TagsAttached $event
     * @return void
     */
    public function handleTagsAttached(TagsAttached $event): void
    {
        try {
            // Clear content-specific caches
            Cache::tags(['content:' . $event->contentId, 'tags'])->flush();

            // Log the activity
            $this->activityLogger->log(
                'tags.attached',
                'Tags attached to content',
                ['content_id' => $event->contentId, 'tags' => $event->tags->pluck('id')]
            );

            // Update search indices
            $this->searchIndexer->updateContentTags($event->contentId);
        } catch (\Exception $e) {
            Log::error('Failed to handle tags attached event', [
                'content_id' => $event->contentId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Handle tags merged events.
     *
     * @param TagsMerged $event
     * @return void
     */
    public function handleTagsMerged(TagsMerged $event): void
    {
        try {
            // Update search indices
            $this->searchIndexer->handleTagMerge(
                $event->sourceTagId,
                $event->targetTag
            );

            // Log the activity
            $this->activityLogger->log(
                'tags.merged',
                "Tag {$event->sourceTagId} merged into {$event->targetTag->name}",
                [
                    'source_tag_id' => $event->sourceTagId,
                    'target_tag_id' => $event->targetTag->id
                ]
            );

            // Clear all tag-related caches
            $this->clearTagCaches();
        } catch (\Exception $e) {
            Log::error('Failed to handle tags merged event', [
                'source_tag' => $event->sourceTagId,
                'target_tag' => $event->targetTag->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Clear tag-related caches.
     *
     * @return void
     */
    protected function clearTagCaches(): void
    {
        Cache::tags(['tags'])->flush();
    }

    /**
     * Register the listeners for the subscriber.
     *
     * @param \Illuminate\Events\Dispatcher $events
     * @return array
     */
    public function subscribe($events): array
    {
        return [
            TagCreated::class => 'handleTagCreated',
            TagUpdated::class => 'handleTagUpdated',
            TagsAttached::class => 'handleTagsAttached',
            TagsMerged::class => 'handleTagsMerged',
        ];
    }
}
