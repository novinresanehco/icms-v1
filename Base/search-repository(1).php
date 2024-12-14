<?php

namespace App\Repositories;

use App\Models\Search;
use App\Repositories\Contracts\SearchRepositoryInterface;
use Illuminate\Support\Collection;

class SearchRepository extends BaseRepository implements SearchRepositoryInterface
{
    protected array $searchableTypes = ['content', 'user', 'media', 'comment'];
    protected array $filterableFields = ['type', 'status', 'locale'];

    public function search(string $query, array $filters = []): Collection
    {
        $results = collect();

        foreach ($this->searchableTypes as $type) {
            if (!isset($filters['type']) || $filters['type'] === $type) {
                $results = $results->merge(
                    $this->searchByType($type, $query, $filters)
                );
            }
        }

        return $results->sortByDesc('relevance');
    }

    protected function searchByType(string $type, string $query, array $filters): Collection
    {
        $modelClass = $this->getModelClass($type);
        $model = new $modelClass;

        return $model->search($query)
            ->when(isset($filters['status']), function ($q) use ($filters) {
                return $q->where('status', $filters['status']);
            })
            ->when(isset($filters['locale']), function ($q) use ($filters) {
                return $q->where('locale', $filters['locale']);
            })
            ->get()
            ->map(function ($result) use ($type) {
                return [
                    'id' => $result->id,
                    'type' => $type,
                    'title' => $result->title ?? $result->name,
                    'excerpt' => $result->excerpt ?? null,
                    'url' => $result->url,
                    'relevance' => $result->relevance
                ];
            });
    }

    public function logSearch(string $query, array $filters = []): void
    {
        $this->create([
            'query' => $query,
            'filters' => $filters,
            'user_id' => auth()->id(),
            'ip' => request()->ip(),
            'results_count' => 0
        ]);
    }

    public function getPopularSearches(int $limit = 10): Collection
    {
        return $this->model->select('query')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('query')
            ->orderByDesc('count')
            ->limit($limit)
            ->get();
    }

    protected function getModelClass(string $type): string
    {
        return match($type) {
            'content' => \App\Models\Content::class,
            'user' => \App\Models\User::class,
            'media' => \App\Models\Media::class,
            'comment' => \App\Models\Comment::class,
            default => throw new \InvalidArgumentException("Invalid search type: {$type}")
        };
    }
}
