<?php

namespace App\Core\Tag\Services;

use App\Core\Tag\Models\Tag;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TagAnalyticsService
{
    /**
     * Get tag usage statistics.
     */
    public function getTagUsageStats(): array
    {
        return [
            'total_tags' => Tag::count(),
            'total_tagged_content' => $this->getTaggedContentCount(),
            'average_tags_per_content' => $this->getAverageTagsPerContent(),
            'popular_tags' => $this->getPopularTags(5),
            'unused_tags' => $this->getUnusedTagsCount(),
        ];
    }

    /**
     * Get tag usage trends over time.
     */
    public function getTagUsageTrends(int $days = 30): Collection
    {
        return DB::table('taggables')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->where('created_at', '>=', now()->subDays($days))
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }

    /**
     * Get content suggestions based on tags.
     */
    public function getContentSuggestions(int $contentId, int $limit = 5): Collection
    {
        $tags = DB::table('taggables')
            ->where('taggable_id', $contentId)
            ->where('taggable_type', 'App\Core\Content\Models\Content')
            ->pluck('tag_id');

        return DB::table('taggables')
            ->select('taggable_id', DB::raw('COUNT(*) as matched_tags'))
            ->whereIn('tag_id', $tags)
            ->where('taggable_id', '!=', $contentId)
            ->groupBy('taggable_id')
            ->orderByDesc('matched_tags')
            ->limit($limit)
            ->get();
    }

    /**
     * Get related tags based on co-occurrence.
     */
    public function getRelatedTags(int $tagId, int $limit = 5): Collection
    {
        return DB::table('taggables as t1')
            ->join('taggables as t2', function ($join) {
                $join->on('t1.taggable_id', '=', 't2.taggable_id')
                     ->on('t1.taggable_type', '=', 't2.taggable_type');
            })
            ->where('t1.tag_id', $tagId)
            ->where('t2.tag_id', '!=', $tagId)
            ->select('t2.tag_id', DB::raw('COUNT(*) as frequency'))
            ->groupBy('t2.tag_id')
            ->orderByDesc('frequency')
            ->limit($limit)
            ->get();
    }

    /**
     * Get orphaned tags (tags without any content).
     */
    public function getOrphanedTags(): Collection
    {
        return Tag::whereNotExists(function ($query) {
            $query->select(DB::raw(1))
                  ->from('taggables')
                  ->whereColumn('taggables.tag_id', 'tags.id');
        })->get();
    }

    /**
     * Calculate tag similarity between two contents.
     */
    public function calculateTagSimilarity(int $contentId1, int $contentId2): float
    {
        $tags1 = $this->getContentTags($contentId1);
        $tags2 = $this->getContentTags($contentId2);

        $intersection = $tags1->intersect($tags2)->count();
        $union = $tags1->union($tags2)->count();

        return $union > 0 ? $intersection / $union : 0;
    }

    protected function getContentTags(int $contentId): Collection
    {
        return DB::table('taggables')
            ->where('taggable_id', $contentId)
            ->where('taggable_type', 'App\Core\Content\Models\Content')
            ->pluck('tag_id');
    }

    protected function getTaggedContentCount(): int
    {
        return DB::table('taggables')
            ->where('taggable_type', 'App\Core\Content\Models\Content')
            ->distinct('taggable_id')
            ->count();
    }

    protected function getAverageTagsPerContent(): float
    {
        return DB::table('taggables')
            ->where('taggable_type', 'App\Core\Content\Models\Content')
            ->select(DB::raw('AVG(tag_count) as average'))
            ->fromSub(function ($query) {
                $query->from('taggables')
                      ->select('taggable_id', DB::raw('COUNT(*) as tag_count'))
                      ->where('taggable_type', 'App\Core\Content\Models\Content')
                      ->groupBy('taggable_id');
            }, 'tag_counts')
            ->value('average') ?? 0;
    }

    protected function getUnusedTagsCount(): int
    {
        return $this->getOrphanedTags()->count();
    }
}
