<?php

namespace App\Core\Repository;

use App\Models\SearchIndex;
use App\Core\Events\SearchEvents;
use App\Core\Exceptions\SearchRepositoryException;
use Illuminate\Support\Collection;

class SearchRepository extends BaseRepository
{
    protected function getModelClass(): string
    {
        return SearchIndex::class;
    }

    /**
     * Perform search across all indexed content
     */
    public function search(string $query, array $filters = [], array $options = []): Collection
    {
        try {
            $searchQuery = $this->model->newQuery();

            // Apply full-text search
            $searchQuery->whereRaw(
                "MATCH(title, content) AGAINST(? IN BOOLEAN MODE)",
                [$this->prepareSearchQuery($query)]
            );

            // Apply filters
            foreach ($filters as $field => $value) {
                $searchQuery->where($field, $value);
            }

            // Apply options
            if (isset($options['type'])) {
                $searchQuery->where('indexable_type', $options['type']);
            }

            if (isset($options['limit'])) {
                $searchQuery->limit($options['limit']);
            }

            // Load relationships if specified
            if (isset($options['with'])) {
                $searchQuery->with($options['with']);
            }

            $results = $searchQuery->get();

            // Record search metrics
            $this->recordSearchMetrics($query, $results->count());

            return $results;

        } catch (\Exception $e) {
            throw new SearchRepositoryException(
                "Search operation failed: {$e->getMessage()}"
            );
        }
    }

    /**
     * Index content for searching
     */
    public function indexContent(string $type, int $id, array $data): void
    {
        try {
            $existingIndex = $this->model
                ->where('indexable_type', $type)
                ->where('indexable_id', $id)
                ->first();

            $indexData = [
                'title' => $data['title'],
                'content' => $data['content'],
                'metadata' => $data['metadata'] ?? [],
                'status' => $data['status'] ?? 'active',
                'last_indexed' => now()
            ];

            if ($existingIndex) {
                $existingIndex->update($indexData);
            } else {
                $this->create(array_merge($indexData, [
                    'indexable_type' => $type,
                    'indexable_id' => $id
                ]));
            }

            $this->clearCache();
            event(new SearchEvents\ContentIndexed($type, $id));

        } catch (\Exception $e) {
            throw new SearchRepositoryException(
                "Failed to index content: {$e->getMessage()}"
            );
        }
    }

    /**
     * Remove content from search index
     */
    public function removeFromIndex(string $type, int $id): void
    {
        try {
            $this->model
                ->where('indexable_type', $type)
                ->where('indexable_id', $id)
                ->delete();

            $this->clearCache();
            event(new SearchEvents\ContentRemovedFromIndex($type, $id));

        } catch (\Exception $e) {
            throw new SearchRepositoryException(
                "Failed to remove content from index: {$e->getMessage()}"
            );
        }
    }

    /**
     * Rebuild search index
     */
    public function rebuildIndex(): void
    {
        try {
            DB::beginTransaction();

            // Clear existing index
            $this->model->truncate();

            // Index all content types
            $this->indexAllContent();
            $this->indexAllCategories();
            $this->indexAllTags();

            DB::commit();
            $this->clearCache();
            event(new SearchEvents\SearchIndexRebuilt());

        } catch (\Exception $e) {
            DB::rollBack();
            throw new SearchRepositoryException(
                "Failed to rebuild search index: {$e->getMessage()}"
            );
        }
    }

    /**
     * Get search suggestions
     */
    public function getSuggestions(string $query, int $limit = 5): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("suggestions.{$query}.{$limit}"),
            300, // 5 minutes cache
            fn() => $this->model
                ->select('title')
                ->whereRaw(
                    "title LIKE ?",
                    ["{$query}%"]
                )
                ->distinct()
                ->limit($limit)
                ->get()
                ->pluck('title')
        );
    }

    /**
     * Get popular searches
     */
    public function getPopularSearches(int $limit = 10): Collection
    {
        return Cache::tags($this->getCacheTags())->remember(
            $this->getCacheKey("popular.{$limit}"),
            3600, // 1 hour cache
            fn() => DB::table('search_logs')
                ->select('query', DB::raw('count(*) as count'))
                ->where('created_at', '>=', now()->subDays(30))
                ->groupBy('query')
                ->orderByDesc('count')
                ->limit($limit)
                ->get()
        );
    }

    /**
     * Prepare search query
     */
    protected function prepareSearchQuery(string $query): string
    {
        // Clean and prepare query for MySQL full-text search
        $query = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $query);
        $terms = explode(' ', $query);
        
        return implode(' ', array_map(function($term) {
            return '+' . $term . '*';
        }, array_filter($terms)));
    }

    /**
     * Record search metrics
     */
    protected function recordSearchMetrics(string $query, int $resultCount): void
    {
        DB::table('search_logs')->insert([
            'query' => $query,
            'results_count' => $resultCount,
            'user_id' => auth()->id(),
            'created_at' => now()
        ]);
    }
}
