<?php

namespace App\Core\Logging\Search;

class LogSearchEngine implements SearchEngineInterface
{
    private IndexManager $indexManager;
    private SearchOptimizer $optimizer;
    private QueryBuilder $queryBuilder;
    private ResultFormatter $formatter;
    private MetricsCollector $metrics;

    public function __construct(
        IndexManager $indexManager,
        SearchOptimizer $optimizer,
        QueryBuilder $queryBuilder,
        ResultFormatter $formatter,
        MetricsCollector $metrics
    ) {
        $this->indexManager = $indexManager;
        $this->optimizer = $optimizer;
        $this->queryBuilder = $queryBuilder;
        $this->formatter = $formatter;
        $this->metrics = $metrics;
    }

    public function search(SearchQuery $query): SearchResult
    {
        $startTime = microtime(true);

        try {
            // Validate and optimize query
            $optimizedQuery = $this->optimizer->optimize($query);

            // Build search context
            $context = $this->buildSearchContext($optimizedQuery);

            // Execute search
            $rawResults = $this->executeSearch($optimizedQuery, $context);

            // Process and format results
            $results = $this->processResults($rawResults, $optimizedQuery);

            // Record metrics
            $this->recordSearchMetrics($query, $results, microtime(true) - $startTime);

            return $results;

        } catch (\Exception $e) {
            $this->handleSearchError($query, $e);
            throw $e;
        }
    }

    public function advancedSearch(AdvancedSearchQuery $query): AdvancedSearchResult
    {
        $startTime = microtime(true);

        try {
            // Build complex query
            $searchQuery = $this->buildAdvancedQuery($query);

            // Add aggregations if requested
            if ($query->hasAggregations()) {
                $searchQuery = $this->addAggregations($searchQuery, $query->getAggregations());
            }

            // Execute search
            $results = $this->search($searchQuery);

            // Process aggregations
            if ($query->hasAggregations()) {
                $results->setAggregations(
                    $this->processAggregations($results->getRawAggregations())
                );
            }

            // Add analytics if requested
            if ($query->includeAnalytics()) {
                $results->setAnalytics(
                    $this->generateAnalytics($results)
                );
            }

            return new AdvancedSearchResult($results);

        } catch (\Exception $e) {
            $this->handleAdvancedSearchError($query, $e);
            throw $e;
        }
    }

    protected function buildSearchContext(SearchQuery $query): SearchContext
    {
        return new SearchContext([
            'indices' => $this->determineSearchIndices($query),
            'filters' => $this->buildFilters($query),
            'sorting' => $this->buildSorting($query),
            'pagination' => $query->getPagination(),
            'highlight' => $query->getHighlightConfig(),
            'preferences' => $this->getUserPreferences()
        ]);
    }

    protected function executeSearch(SearchQuery $query, SearchContext $context): array
    {
        // Get optimal indices
        $indices = $context->getIndices();

        // Build query
        $searchQuery = $this->queryBuilder
            ->newQuery()
            ->setIndices($indices)
            ->setQuery($query->getQuery())
            ->setFilters($context->getFilters())
            ->setSorting($context->getSorting())
            ->setPagination($context->getPagination())
            ->setHighlight($context->getHighlight())
            ->build();

        // Execute search
        return $this->indexManager->search($searchQuery);
    }

    protected function processResults(array $rawResults, SearchQuery $query): SearchResult
    {
        // Extract hits
        $hits = $this->extractHits($rawResults);

        // Format results
        $formattedResults = $this->formatter->format($hits, $query);

        // Add metadata
        $metadata = $this->buildResultMetadata($rawResults, $query);

        return new SearchResult([
            'hits' => $formattedResults,
            'total' => $rawResults['total'] ?? count($hits),
            'metadata' => $metadata,
            'facets' => $this->extractFacets($rawResults),
            'highlight' => $this->extractHighlight($rawResults),
            'suggestions' => $this->generateSuggestions($rawResults, $query)
        ]);
    }

    protected function buildAdvancedQuery(AdvancedSearchQuery $query): SearchQuery
    {
        $searchQuery = new SearchQuery();

        // Add main query
        $searchQuery->setQuery($query->getQuery());

        // Add filters
        foreach ($query->getFilters() as $filter) {
            $searchQuery->addFilter($filter);
        }

        // Add sorting
        if ($query->hasSorting()) {
            $searchQuery->setSorting($query->getSorting());
        }

        // Add pagination
        $searchQuery->setPagination($query->getPagination());

        // Add highlighting
        if ($query->hasHighlighting()) {
            $searchQuery->setHighlight($query->getHighlightConfig());
        }

        return $searchQuery;
    }

    protected function addAggregations(SearchQuery $query, array $aggregations): SearchQuery
    {
        foreach ($aggregations as $name => $config) {
            $query->addAggregation(
                $this->buildAggregation($name, $config)
            );
        }

        return $query;
    }

    protected function processAggregations(array $rawAggregations): array
    {
        $processed = [];

        foreach ($rawAggregations as $name => $data) {
            $processed[$name] = $this->processAggregation($name, $data);
        }

        return $processed;
    }

    protected function generateAnalytics(SearchResult $results): array
    {
        return [
            'performance' => [
                'response_time' => $results->getResponseTime(),
                'total_hits' => $results->getTotal(),
                'precision' => $this->calculatePrecision($results)
            ],
            'patterns' => $this->analyzeResultPatterns($results),
            'insights' => $this->generateInsights($results),
            'recommendations' => $this->generateRecommendations($results)
        ];
    }

    protected function recordSearchMetrics(SearchQuery $query, SearchResult $results, float $duration): void
    {
        $this->metrics->increment('search.queries');
        $this->metrics->timing('search.duration', $duration);
        $this->metrics->gauge('search.hits', $results->getTotal());

        if ($results->hasErrors()) {
            $this->metrics->increment('search.errors');
        }

        // Record query complexity
        $complexity = $this->calculateQueryComplexity($query);
        $this->metrics->gauge('search.complexity', $complexity);
    }

    protected function handleSearchError(SearchQuery $query, \Exception $e): void
    {
        // Log error
        Log::error('Search operation failed', [
            'query' => $query->toArray(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        // Update metrics
        $this->metrics->increment('search.errors');

        // Notify if critical
        if ($this->isCriticalError($e)) {
            $this->notifySearchError($query, $e);
        }
    }
}
