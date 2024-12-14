<?php

namespace App\Core\Repositories;

use App\Core\Repositories\Contracts\SearchRepositoryInterface;
use App\Models\SearchIndex;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;

class SearchRepository extends BaseRepository implements SearchRepositoryInterface
{
    public function __construct(SearchIndex $model)
    {
        parent::__construct($model);
    }

    public function search(string $query, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $searchQuery = $this->model->newQuery();

        // Full text search on content and title
        $searchQuery->whereRaw("MATCH(title, content) AGAINST(? IN BOOLEAN MODE)", [$query]);

        // Apply filters
        if (!empty($filters['type'])) {
            $searchQuery->where('indexable_type', $filters['type']);
        }

        if (!empty($filters['date_from'])) {
            $searchQuery->where('indexed_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $searchQuery->where('indexed_at', '<=', $filters['date_to']);
        }

        // Apply relevance scoring
        $searchQuery->selectRaw("
            *, 
            (
                MATCH(title) AGAINST(? IN BOOLEAN MODE) * 2 +
                MATCH(content) AGAINST(? IN BOOLEAN MODE)
            ) as relevance", 
            [$query, $query]
        );

        return $searchQuery
            ->orderByDesc('relevance')
            ->paginate($perPage);
    }

    public function indexContent(string $type, int $id, array $data): bool
    {
        return $this->model->updateOrCreate(
            [
                'indexable_type' => $type,
                'indexable_id' => $id
            ],
            [
                'title' => $data['title'],
                'content' => $data['content'],
                'metadata' => $data['metadata'] ?? [],
                'indexed_at' => now()
            ]
        ) instanceof SearchIndex;
    }

    public function removeFromIndex(string $type, int $id): bool
    {
        return $this->model
            ->where('indexable_type', $type)
            ->where('indexable_id', $id)
            ->delete() > 0;
    }

    public function getSuggestions(string $query, int $limit = 5): Collection
    {
        return $this->model
            ->whereRaw("MATCH(title, content) AGAINST(? IN BOOLEAN MODE)", [$query])
            ->selectRaw("
                title,
                MATCH(title, content) AGAINST(? IN BOOLEAN MODE) as relevance",
                [$query]
            )
            ->orderByDesc('relevance')
            ->limit($limit)
            ->get()
            ->pluck('title');
    }

    public function getPopularSearches(int $limit = 10): Collection
    {
        return DB::table('search_logs')
            ->select('query', DB::raw('COUNT(*) as count'))
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('query')
            ->orderByDesc('count')
            ->limit($limit)
            ->get();
    }

    public function logSearch(string $query, ?int $userId = null): void
    {
        DB::table('search_logs')->insert([
            'query' => $query,
            'user_id' => $userId,
            'results_count' => $this->model
                ->whereRaw("MATCH(title, content) AGAINST(? IN BOOLEAN MODE)", [$query])
                ->count(),
            'created_at' => now()
        ]);
    }

    public function reindexAll(): int
    {
        $count = 0;
        
        // Clear existing index
        $this->model->truncate();

        // Reindex all content types
        foreach (config('search.indexable_types') as $type => $config) {
            $modelClass = $config['model'];
            $items = $modelClass::all();

            foreach ($items as $item) {
                if ($this->indexContent($type, $item->id, [
                    'title' => $item->{$config['title_field']},
                    'content' => $item->{$config['content_field']},
                    'metadata' => $item->{$config['metadata_field'] ?? 'metadata'} ?? []
                ])) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
