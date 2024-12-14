<?php

namespace App\Core\Tag\Services;

use App\Core\Tag\Models\Tag;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TagSearchService
{
    /**
     * Search tags with relevance scoring.
     */
    public function searchTags(string $query, array $options = []): Collection
    {
        $query = $this->prepareSearchQuery($query);

        return Tag::query()
            ->select([
                'tags.*',
                DB::raw($this->buildRelevanceSQL())
            ])
            ->where(function ($q) use ($query) {
                $q->where('name', 'LIKE', "%{$query}%")
                  ->orWhere('description', 'LIKE', "%{$query}%");
            })
            ->when(
                isset($options['min_usage']),
                fn($q) => $q->having('content_count', '>=', $options['min_usage'])
            )
            ->when(
                isset($options['category']),
                fn($q) => $q->whereHas('category', fn($q) => 
                    $q->where('name', $options['category'])
                )
            )
            ->orderByDesc('relevance')
            ->limit($options['limit'] ?? 10)
            ->get();
    }

    /**
     * Get tag suggestions based on partial input.
     */
    public function getTagSuggestions(string $partial, int $limit = 5): Collection
    {
        return Tag::where('name', 'LIKE', "{$partial}%")
            ->orderByContentCount('desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Find similar tags based on name similarity.
     */
    public function findSimilarTags(string $name, float $threshold = 0.8): Collection
    {
        return Tag::all()
            ->filter(function ($tag) use ($name, $threshold) {
                return similar_text($tag->name, $name, $percent) && 
                       $percent >= ($threshold * 100);
            });
    }

    protected function prepareSearchQuery(string $query): string
    {
        return str_replace(['%', '_'], ['\%', '\_'], $query);
    }

    protected function buildRelevanceSQL(): string
    {
        return "
            (CASE
                WHEN name LIKE ? THEN 100
                WHEN name LIKE ? THEN 50
                WHEN description LIKE ? THEN 25
                ELSE 0
            END) as relevance
        ";
    }
}
