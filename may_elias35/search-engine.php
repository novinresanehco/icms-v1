<?php

namespace App\Core\Audit;

class AuditSearchEngine
{
    private IndexManager $indexManager;
    private SearchOptimizer $optimizer;
    private QueryBuilder $queryBuilder;
    private ResultProcessor $resultProcessor;
    private CacheManager $cache;
    private MetricsCollector $metrics;

    public function __construct(
        IndexManager $indexManager,
        SearchOptimizer $optimizer,
        QueryBuilder $queryBuilder,
        ResultProcessor $resultProcessor,
        CacheManager $cache,
        MetricsCollector $metrics
    ) {
        $this->indexManager = $indexManager;
        $this->optimizer = $optimizer;
        $this->queryBuilder = $queryBuilder;
        $this->resultProcessor = $resultProcessor;
        $this->cache = $cache;
        $this->metrics = $metrics;
    }

    public function search(SearchQuery $query): SearchResult
    {
        $startTime = microtime(true);

        try {
            // Check cache
            $cacheKey = $this->generateCacheKey($query);
            if ($cached = $this->cache->get($cacheKey)) {
                $this->metrics->increment('search_cache_hits');
                return $cached;
            }

            // Validate and optimize query
            $optimizedQuery = $this->optimizer->optimize($query);

            // Build search parameters
            $params = $this->buildSearchParams($optimizedQuery);

            // Execute search
            $rawResults = $this->executeSearch($params);

            // Process results
            $results = $this->processResults($rawResults, $optimizedQuery);

            // Cache results
            if ($query->isCacheable()) {
                $this->cache->put($cacheKey, $results, $query->getCacheDuration());
            }

            // Record metrics
            $this->recordSearchMetrics($query, $results, microtime(true) - $startTime);

            return $results;

        } catch (\Exception $e) {
            $this->handleSearchError($e, $query);
            throw $e;
        }
    }

    public function suggest(string $term, array $options = []): array
    {
        try {
            // Generate suggestions
            $suggestions = $this->indexManager->suggest($term, $options);

            // Filter and rank suggestions
            $rankedSuggestions = $this->rankSuggestions($suggestions, $term);

            // Limit results
            return array_slice(
                $rankedSuggestions, 
                0, 
                $options['limit'] ?? 10
            );

        } catch (\Exception $e) {
            $this->handleSuggestionError($e, $term);
            throw $e;
        }
    }

    protected function buildSearchParams(SearchQuery $query): array
    {
        return $this->queryBuilder
            ->setQuery($query)
            ->addFilters()
            ->addAggregations()
            ->addSorting()
            ->addPagination()
            ->build();
    }

    protected function executeSearch(array $params): array
    {
        $this->validateSearchParams($params);

        return $this->indexManager->search($params);
    }

    protected function processResults(array $rawResults, SearchQuery $query): SearchResult
    {
        return $this->resultProcessor
            ->setResults($rawResults)
            ->setQuery($query)
            ->addHighlighting()
            ->addFacets()
            ->addMetadata()
            ->process();
    }

    protected function rankSuggestions(array $suggestions, string $term): array
    {
        return collect($suggestions)
            ->map(function ($suggestion) use ($term) {
                return [
                    'text' => $suggestion,
                    'score' => $this->calculateSuggestionScore($suggestion, $term)
                ];
            })
            ->sortByDesc('score')
            ->pluck('text')
            ->toArray();
    }

    protected function calculateSuggestionScore(string $suggestion, string $term): float
    {
        $score = 0;

        // Exact prefix match
        if (strpos(strtolower($suggestion), strtolower($term)) === 0) {
            $score += 10;
        }

        // Levenshtein distance
        $score += 1 / (levenshtein($term, $suggestion) + 1);

        // Popularity boost
        $score += $this->getPopularityScore($suggestion);

        return $score;
    }

    protected function getPopularityScore(string $term): float
    {
        return $this->cache->remember(
            "search_popularity:{$term}",
            3600,
            fn() => $this->calculatePopularityScore($term)
        );
    }

    protected function validateSearchParams(array $params): void
    {
        $validator = new SearchParamsValidator();
        
        if (!$validator->validate($params)) {
            throw new InvalidSearchParamsException(
                'Invalid search parameters: ' . json_encode($validator->getErrors())
            );
        }
    }

    protected function generateCacheKey(SearchQuery $query): string
    {
        return 'search:' . md5(serialize([
            'query' => $query->toArray(),
            'timestamp' => floor(time() / ($query->getCacheDuration() ?? 3600))
        ]));
    }

    protected function recordSearchMetrics(
        SearchQuery $query,
        SearchResult $results,
        float $duration
    ): void {
        $this->metrics->record([
            'search_duration' => $duration,
            'search_results_count' => $results->count(),
            'search_terms' => $query->getTerms(),
            'search_filters_count' => count($query->getFilters()),
            'search_cache_miss' => 1
        ]);
    }
}
