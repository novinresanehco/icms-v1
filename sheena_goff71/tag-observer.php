<?php

namespace App\Core\Tag\Repository\Observers;

use App\Core\Tag\Models\Tag;
use App\Core\Tag\Events\{
    TagCreated,
    TagUpdated,
    TagDeleted,
    TagRestored,
    TagForceDeleted
};
use App\Core\Tag\Services\TagCacheService;
use App\Core\Search\Services\SearchIndexService;

class TagObserver
{
    /**
     * @var TagCacheService
     */
    protected TagCacheService $cacheService;

    /**
     * @var SearchIndexService
     */
    protected SearchIndexService $searchService;

    public function __construct(
        TagCacheService $cacheService,
        SearchIndexService $searchService
    ) {
        $this->cacheService = $cacheService;
        $this->searchService = $searchService;
    }

    /**
     * Handle the Tag "creating" event.
     */
    public function creating(Tag $tag): void
    {
        // Generate slug if not provided
        if (empty($tag->slug)) {
            $tag->slug = str_slug($tag->name);
        }

        // Set metadata
        $tag->metadata = array_merge($tag->metadata ?? [], [
            'created_by' => auth()->id() ?? 1,
            'ip_address' => request()->ip()
        ]);
    }

    /**
     * Handle the Tag "created" event.
     */
    public function created(Tag $tag): void
    {
        // Clear relevant caches
        $this->cacheService->clearTagCache();

        // Index for search
        $this->searchService->indexTag($tag);

        // Dispatch event
        event(new TagCreated($tag));
    }

    /**
     * Handle the Tag "updating" event.
     */
    public function updating(Tag $tag): void
    {
        // Update metadata
        $tag->metadata = array_merge($tag->metadata ?? [], [
            'last_updated_by' => auth()->id() ?? 1,
            'last_updated_at' => now(),
            'update_ip' => request()->ip()
        ]);

        // Update slug if name changed
        if ($tag->isDirty('name')) {
            $tag->slug = str_slug($tag->name);
        }
    }

    /**
     * Handle the Tag "updated" event.
     */
    public function updated(Tag $tag): void
    {
        // Clear relevant caches
        $this->cacheService->clearTagCache($tag->id);

        // Update search index
        $this->searchService->updateTag($tag);

        // Dispatch event
        event(new TagUpdated($tag));
    }

    /**
     * Handle the Tag "deleting" event.
     */
    public function deleting(Tag $tag): void
    {
        // Log deletion
        activity()
            ->performedOn($tag)
            ->withProperties([
                'tag_id' => $tag->id,
                'tag_name' => $tag->name,
                'deleted_by' => auth()->id() ?? 1
            ])
            ->log('tag_deleted');
    }

    /**
     * Handle the Tag "deleted" event.
     */
    public function deleted(Tag $tag): void
    {
        // Clear caches
        $this->cacheService->clearTagCache($tag->id);

        // Remove from search index
        $this->searchService->removeTag($tag->id);

        // Dispatch event
        event(new TagDeleted($tag));
    }

    /**
     * Handle the Tag "restored" event.
     */
    public function restored(Tag $tag): void
    {
        // Reindex for search
        $this->searchService->indexTag($tag);

        // Dispatch event
        event(new TagRestored($tag));
    }

    /**
     * Handle the Tag "force deleted" event.
     */
    public function forceDeleted(Tag $tag): void
    {
        // Permanently remove from search index
        $this->searchService->removeTag($tag->id, true);

        // Clear all related caches
        $this->cacheService->clearTagCache($tag->id);

        // Dispatch event
        event(new TagForceDeleted($tag));
    }
}
