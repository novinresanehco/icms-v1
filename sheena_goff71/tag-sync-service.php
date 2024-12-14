<?php

namespace App\Core\Tag\Services;

use App\Core\Tag\Models\Tag;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Core\Tag\Events\TagsSynchronized;
use App\Core\Tag\Exceptions\TagSyncException;
use App\Core\Tag\Contracts\TagSyncInterface;

class TagSyncService implements TagSyncInterface
{
    /**
     * @var TagService
     */
    protected TagService $tagService;

    /**
     * @var TagCacheService
     */
    protected TagCacheService $cacheService;

    public function __construct(
        TagService $tagService,
        TagCacheService $cacheService
    ) {
        $this->tagService = $tagService;
        $this->cacheService = $cacheService;
    }

    /**
     * Synchronize content tags.
     */
    public function syncContentTags(int $contentId, array $tagIds): void
    {
        DB::beginTransaction();
        
        try {
            // Remove existing tags
            $existingTags = $this->tagService->getContentTags($contentId);
            $this->detachTags($contentId, $existingTags->pluck('id')->toArray());

            // Attach new tags
            $this->attachTags($contentId, $tagIds);

            // Update cache
            $this->cacheService->invalidateContentTags($contentId);

            DB::commit();

            event(new TagsSynchronized($contentId, $tagIds));
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TagSyncException("Failed to sync tags: {$e->getMessage()}");
        }
    }

    /**
     * Bulk synchronize tags for multiple contents.
     */
    public function bulkSyncTags(array $contentTagMap): void
    {
        DB::beginTransaction();

        try {
            foreach ($contentTagMap as $contentId => $tagIds) {
                $this->syncContentTags($contentId, $tagIds);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TagSyncException("Failed to bulk sync tags: {$e->getMessage()}");
        }
    }

    /**
     * Reorder content tags.
     */
    public function reorderTags(int $contentId, array $orderedTagIds): void
    {
        DB::beginTransaction();

        try {
            foreach ($orderedTagIds as $order => $tagId) {
                DB::table('taggables')
                    ->where('taggable_id', $contentId)
                    ->where('taggable_type', 'App\Core\Content\Models\Content')
                    ->where('tag_id', $tagId)
                    ->update(['order' => $order]);
            }

            $this->cacheService->invalidateContentTags($contentId);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new TagSyncException("Failed to reorder tags: {$e->getMessage()}");
        }
    }

    /**
     * Sync tag relationships.
     */
    protected function attachTags(int $contentId, array $tagIds): void
    {
        $insertData = array_map(function ($tagId) use ($contentId) {
            return [
                'tag_id' => $tagId,
                'taggable_id' => $contentId,
                'taggable_type' => 'App\Core\Content\Models\Content',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }, $tagIds);

        DB::table('taggables')->insert($insertData);
    }

    /**
     * Remove tag relationships.
     */
    protected function detachTags(int $contentId, array $tagIds): void
    {
        DB::table('taggables')
            ->where('taggable_id', $contentId)
            ->where('taggable_type', 'App\Core\Content\Models\Content')
            ->whereIn('tag_id', $tagIds)
            ->delete();
    }
}
