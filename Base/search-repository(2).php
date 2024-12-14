<?php

namespace App\Core\Repositories;

use App\Core\Models\{Search, SearchIndex, SearchLog};
use App\Core\Contracts\Searchable;
use Illuminate\Database\Eloquent\{Model, Collection, Builder};
use Illuminate\Support\Facades\DB;

class SearchRepository extends Repository
{
    protected array $with = ['searchable'];
    protected int $searchTimeout = 30;

    public function index(Searchable $model): void
    {
        DB::transaction(function() use ($model) {
            $this->deleteIndex($model);

            $indexData = $model->toSearchArray();
            $tokens = $this->tokenize($indexData['content']);

            foreach ($tokens as $token => $frequency) {
                SearchIndex::create([
                    'searchable_type' => get_class($model),
                    'searchable_id' => $model->getId(),
                    'token' => $token,
                    'frequency' => $frequency,
                    'boost' => $indexData['boost'] ?? 1.0
                ]);
            }
        });
    }

    public function search(string $query, array $filters = []): Collection
    {
        $tokens = $this->tokenize($query);
        $baseQuery = $this->buildSearchQuery($tokens, $filters);

        return $baseQuery
            ->select('searchable_type', 'searchable_id')
            ->selectRaw('SUM(frequency * boost) as relevance')
            ->groupBy('searchable_type', 'searchable_id')
            ->orderByDesc('relevance')
            ->with('searchable')
            ->get()
            ->map(fn($result) => $result->searchable);
    }

    public function suggest(string $query, int $limit = 5): array
    {
        $tokens = $this->tokenize($query);
        $lastToken = end($tokens);

        return SearchIndex::where('token', 'LIKE', $lastToken . '%')
            ->select('token')
            ->selectRaw('COUNT(*) as frequency')
            ->groupBy('token')
            ->orderByDesc('frequency')
            ->limit($limit)
            ->pluck('token')
            ->toArray();
    }

    protected function buildSearchQuery(array $tokens, array $filters): Builder
    {
        $query = SearchIndex::query();

        foreach ($tokens as $token => $weight) {
            $query->where(function($q) use ($token) {
                $q->where('token', $token)
                  ->orWhere('token', 'LIKE', $token . '%');
            });
        }

        if (!empty($filters['types'])) {
            $query->whereIn('searchable_type', $filters['types']);
        }

        return $query;
    }

    protected function tokenize(string $content): array
    {
        $content = strtolower($content);
        $content = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $content);
        $tokens = str_word_count($content, 1);
        
        return array_count_values($tokens);
    }

    protected function deleteIndex(Searchable $model): void
    {
        SearchIndex::where([
            'searchable_type' => get_class($model),
            'searchable_id' => $model->getId()
        ])->delete();
    }
}

class SearchLogRepository extends Repository
{
    public function logSearch(string $query, array $filters, int $resultsCount): void
    {
        SearchLog::create([
            'query' => $query,
            'filters' => $filters,
            'results_count' => $resultsCount,
            'user_id' => auth()->id(),
            'ip_address' => request()->ip()
        ]);
    }

    public function getPopularSearches(int $limit = 10): Collection
    {
        return SearchLog::select('query')
            ->selectRaw('COUNT(*) as frequency')
            ->groupBy('query')
            ->orderByDesc('frequency')
            ->limit($limit)
            ->get();
    }

    public function getZeroResultSearches(): Collection
    {
        return SearchLog::where('results_count', 0)
            ->select('query')
            ->selectRaw('COUNT(*) as frequency')
            ->groupBy('query')
            ->orderByDesc('frequency')
            ->get();
    }
}

class SearchSynonymRepository extends Repository
{
    public function addSynonym(string $term, array $synonyms): Model
    {
        return DB::transaction(function() use ($term, $synonyms) {
            return $this->create([
                'term' => $term,
                'synonyms' => $synonyms
            ]);
        });
    }

    public function getSynonyms(string $term): array
    {
        $synonym = $this->query()
            ->where('term', $term)
            ->first();

        return $synonym ? $synonym->synonyms : [];
    }

    public function expandQuery(string $query): array
    {
        $terms = explode(' ', strtolower($query));
        $expanded = [];

        foreach ($terms as $term) {
            $synonyms = $this->getSynonyms($term);
            $expanded[] = $synonyms ? array_merge([$term], $synonyms) : [$term];
        }

        return $expanded;
    }
}

class SearchBoostRepository extends Repository
{
    public function setBoost(string $type, string $field, float $boost): Model
    {
        return $this->create([
            'searchable_type' => $type,
            'field' => $field,
            'boost' => $boost
        ]);
    }

    public function getBoosts(string $type): array
    {
        return $this->query()
            ->where('searchable_type', $type)
            ->pluck('boost', 'field')
            ->toArray();
    }
}
