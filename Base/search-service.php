<?php

namespace App\Core\Services;

use App\Core\Repositories\{SearchRepository, SearchLogRepository, SearchSynonymRepository, SearchBoostRepository};
use App\Core\Contracts\Searchable;
use App\Core\Exceptions\SearchException;
use Illuminate\Database\Eloquent\{Model, Collection};
use Illuminate\Support\Facades\{DB, Cache};

class SearchService extends BaseService
{
    protected SearchLogRepository $logRepository;
    protected SearchSynonymRepository $synonymRepository;
    protected SearchBoostRepository $boostRepository;
    protected int $minQueryLength = 3;
    protected int $maxQueryLength = 100;

    public function __construct(
        SearchRepository $repository,
        SearchLogRepository $logRepository,
        SearchSynonymRepository $synonymRepository,
        SearchBoostRepository $boostRepository
    ) {
        parent::__construct($repository);
        $this->logRepository = $logRepository;
        $this->synonymRepository = $synonymRepository;
        $this->boostRepository = $boostRepository;
    }

    public function search(string $query, array $filters = []): Collection
    {
        try {
            $this->validateQuery($query);

            $expandedQuery = $this->expandSearchQuery($query);
            $results = $this->repository->search($expandedQuery, $filters);
            
            $this->logRepository->logSearch($query, $filters, $results->count());
            
            return $results;
        } catch (\Exception $e) {
            throw new SearchException("Search failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function indexModel(Searchable $model): void
    {
        try {
            DB::beginTransaction();

            $this->repository->index($model);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw new SearchException("Indexing failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function suggest(string $query): array
    {
        try {
            $cacheKey = "search_suggest:{$query}";
            
            return Cache::remember($cacheKey, 3600, function() use ($query) {
                return $this->repository->suggest($query);
            });
        } catch (\Exception $e) {
            throw new SearchException("Suggestion failed: {$e->getMessage()}", 0, $e);
        }
    }

    public function addSynonyms(string $term, array $synonyms): Model
    {
        try {
            DB::beginTransaction();

            $model = $this->synonymRepository->addSynonym($term, $synonyms);

            Cache::tags(['search_synonyms'])->flush();

            DB::commit();

            return $model;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new SearchException("Failed to add synonyms: {$e->getMessage()}", 0, $e);
        }
    }

    public function setBoost(string $type, string $field, float $boost): Model
    {
        try {
            DB::beginTransaction();

            $model = $this->boostRepository->setBoost($type, $field, $boost);

            Cache::tags(['search_boosts'])->flush();

            DB::commit();

            return $model;
        } catch (\Exception $e) {
            DB::rollBack();
            throw new SearchException("Failed to set boost: {$e->getMessage()}", 0, $e);
        }
    }

    public function getPopularSearches(int $limit = 10): Collection
    {
        return Cache::remember('popular_searches', 3600, function() use ($limit) {
            return $this->logRepository->getPopularSearches($limit);
        });
    }

    protected function validateQuery(string $query): void
    {
        $length = mb_strlen($query);

        if ($length < $this->minQueryLength) {
            throw new SearchException("Query too short (minimum {$this->minQueryLength} characters)");
        }

        if ($length > $this->maxQueryLength) {
            throw new SearchException("Query too long (maximum {$this->maxQueryLength} characters)");
        }
    }

    protected function expandSearchQuery(string $query): string
    {
        $expanded = $this->synonymRepository->expandQuery($query);
        
        return implode(' ', array_merge(...$expanded));
    }
}
