<?php

namespace App\Core\Tag\Services;

use App\Core\Tag\Models\Tag;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Core\Tag\Events\TagsCleanedUp;

class TagCleanupService
{
    /**
     * @var TagCacheService
     */
    protected TagCacheService $cacheService;

    public function __construct(TagCacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    /**
     * Clean up unused tags.
     */
    public function cleanupUnusedTags(): int
    {
        $unusedTags = $this->findUnusedTags();
        
        DB::transaction(function () use ($unusedTags) {
            Tag::whereIn('id', $unusedTags->pluck('id'))->delete();
        });

        $this->cacheService->invalidateAllTags();
        
        event(new TagsCleanedUp($unusedTags));

        return $unusedTags->count();
    }

    /**
     * Merge duplicate tags.
     */
    public function mergeDuplicateTags(): array
    {
        $duplicates = $this->findDuplicateTags();
        $mergeCount = 0;

        DB::transaction(function () use ($duplicates, &$mergeCount) {
            foreach ($duplicates as $duplicate) {
                $this->mergeTags($duplicate['original'], $duplicate['duplicates']);
                $mergeCount++;
            }
        });

        $this->cacheService->invalidateAllTags();

        return [
            'merged_groups' => $mergeCount,
            'total_merged' => collect($duplicates)->pluck('duplicates')->flatten()->count()
        ];
    }

    /**
     * Fix invalid tag relationships.
     */
    public function fixInvalidRelationships(): int
    {
        $invalidCount = 0;

        DB::transaction(function () use (&$invalidCount) {
            // Remove relationships with non-existent tags
            $deleted = DB::table('taggables')
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                          ->from('tags')
                          ->whereColumn('tags.id', 'taggables.tag_id');
                })
                ->delete();

            // Remove relationships with non-existent content
            $deleted += DB::table('taggables')
                ->where('taggable_type', 'App\Core\Content\Models\Content')
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                          ->from('contents')
                          ->whereColumn('contents.id', 'taggables.taggable_id');
                })
                ->delete();

            $invalidCount = $deleted;
        });

        $this->cacheService->invalidateAllTags();

        return $invalidCount;
    }

    /**
     * Normalize tag names.
     */
    public function normalizeTagNames(): int
    {
        $normalizedCount = 0;

        DB::transaction(function () use (&$normalizedCount) {
            Tag::chunk(100, function ($tags) use (&$normalizedCount) {
                foreach ($tags as $tag) {
                    $normalized = $this->normalizeTagName($tag->name);
                    if ($normalized !== $tag->name) {
                        $tag->update(['name' => $normalized]);
                        $normalizedCount++;
                    }
                }
            });
        });

        if ($normalizedCount > 0) {
            $this->cacheService->invalidateAllTags();
        }

        return $normalizedCount;
    }

    /**
     * Find unused tags.
     */
    protected function findUnusedTags(): Collection
    {
        return Tag::whereNotExists(function ($query) {
            $query->select(DB::raw(1))
                  ->from('taggables')
                  ->whereColumn('taggables.tag_id', 'tags.id');
        })->get();
    }

    /**
     * Find duplicate tags.
     */
    protected function findDuplicateTags(): array
    {
        $duplicates = [];
        
        Tag::select('name', DB::raw('COUNT(*) as count'))
            ->groupBy('name')
            ->having('count', '>', 1)
            ->get()
            ->each(function ($group) use (&$duplicates) {
                $tags = Tag::where('name', $group->name)
                          ->orderBy('created_at')
                          ->get();
                
                $duplicates[] = [
                    'original' => $tags->first(),
                    'duplicates' => $tags->slice(1)
                ];
            });

        return $duplicates;
    }

    /**
     * Merge tags.
     */
    protected function mergeTags(Tag $target, Collection $duplicates): void
    {
        // Move all relationships to target tag
        foreach ($duplicates as $duplicate) {
            DB::table('taggables')
                ->where('tag_id', $duplicate->id)
                ->update(['tag_id' => $target->id]);

            $duplicate->delete();
        }
    }

    /**
     * Normalize tag name.
     */
    protected function normalizeTagName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', $name));
    }
}
