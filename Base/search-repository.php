<?php

namespace App\Repositories;

use App\Models\Search;
use App\Repositories\Contracts\SearchRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class SearchRepository extends BaseRepository implements SearchRepositoryInterface
{
    protected array $searchableFields = ['query', 'user_id', 'ip_address'];
    protected array $filterableFields = ['type', 'status'];

    public function logSearch(string $query, array $filters = [], array $metadata = []): Search
    {
        return $this->create([
            'query' => $query,
            'filters' => $filters,
            'metadata' => $metadata,
            'user_id' => auth()->id(),
            'ip_address' => request()->ip(),
            'results_count' => $metadata['results_count'] ?? 0,
            'execution_time' => $metadata['execution_time'] ?? 0
        ]);
    }

    public function getPopularSearches(int $limit = 10): Collection
    {
        return Cache::tags(['searches'])->remember('searches.popular', 3600, function() use ($limit) {
            return $this->model
                ->select('query', \DB::raw('count(*) as count'))
                ->groupBy('query')
                ->orderByDesc('count')
                ->limit($limit)
                ->get();
        });
    }

    public function getSearchAnalytics(array $dateRange = []): array
    {
        $query = $this->model->newQuery();

        if (!empty($dateRange)) {
            $query->whereBetween('created_at', $dateRange);
        }

        return [
            'total_searches' => $query->count(),
            'unique_queries' => $query->distinct('query')->count(),
            'average_results' => $query->avg('results_count'),
            'average_time' => $query->avg('execution_time'),
            'no_results_queries' => $this->getNoResultsQueries($dateRange)
        ];
    }

    public function getSimilarSearches(string $query, int $limit = 5): Collection
    {
        return $this->model
            ->where('query', 'like', '%' . $query . '%')
            ->where('query', '!=', $query)
            ->select('query', \DB::raw('count(*) as count'))
            ->groupBy('query')
            ->orderByDesc('count')
            ->limit($limit)
            ->get();
    }

    public function getSearchHistory(int $userId = null, int $limit = 10): Collection
    {
        $query = $this->model->newQuery();

        if ($userId) {
            $query->where('user_id', $userId);
        }

        return $query->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
}
