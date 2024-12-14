<?php

namespace App\Core\Search;

use App\Core\Security\SecurityManager;
use App\Core\Cache\CacheManager;
use App\Core\Events\SearchEvent;
use App\Core\Exceptions\{SearchException, SecurityException};
use Illuminate\Support\Facades\{DB, Log};

class SearchManager implements SearchInterface
{
    private SecurityManager $security;
    private CacheManager $cache;
    private array $config;
    private array $searchableTypes = [];
    private array $activeIndexes = [];

    public function __construct(
        SecurityManager $security,
        CacheManager $cache,
        array $config = []
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->config = array_merge([
            'max_results' => 1000,
            'min_term_length' => 2,
            'cache_ttl' => 3600,
            'fuzzy_matching' => true,
            'relevance_threshold' => 0.5,
            'timeout' => 30
        ], $config);
    }

    public function search(array $query, array $options = []): SearchResult
    {
        return $this->security->executeCriticalOperation(
            function() use ($query, $options) {
                // Validate search query
                $this->validateQuery($query);

                // Generate cache key
                $cacheKey = $this->generateCacheKey($query, $options);

                // Try cache first
                if ($cached = $this->cache->get($cacheKey)) {
                    return $cached;
                }

                // Process search
                $startTime = microtime(true);
                
                try {
                    // Prepare search parameters
                    $parameters = $this->prepareSearchParameters($query, $options);
                    
                    // Execute search across registered types
                    $results = $this->executeSearch($parameters);
                    
                    // Process and rank results
                    $processedResults = $this->processResults($results, $parameters);
                    
                    // Create search result
                    $searchResult = new SearchResult(
                        $processedResults,
                        microtime(true) - $startTime
                    );
                    
                    // Cache results
                    $this->cacheResults($cacheKey, $searchResult);
                    
                    // Log search metrics
                    $this->logSearchMetrics($query, $searchResult);
                    
                    return $searchResult;

                } catch (\Exception $e) {
                    $this->handleSearchError($e, $query);
                    throw $e;
                }
            },
            ['operation' => 'search']
        );
    }

    public function registerType(string $type, SearchableInterface $handler): void
    {
        $this->searchableTypes[$type] = $handler;
    }

    public function buildIndex(string $type = null): void
    {
        $this->security->executeCriticalOperation(
            function() use ($type) {
                if ($type) {
                    $this->buildTypeIndex($type);
                } else {
                    foreach ($this->searchableTypes as $type => $handler) {
                        $this->buildTypeIndex($type);
                    }
                }
            },
            ['operation' => 'build_index']
        );
    }

    protected function validateQuery(array $query): void
    {
        if (empty($query['term'])) {
            throw new SearchException('Search term is required');
        }

        if (strlen($query['term']) < $this->config['min_term_length']) {
            throw new SearchException('Search term too short');
        }

        if (isset($query['type']) && !isset($this->searchableTypes[$query['type']])) {
            throw new SearchException('Invalid search type specified');
        }
    }

    protected function prepareSearchParameters(array $query, array $options): array
    {
        return [
            'term' => $this->sanitizeSearchTerm($query['term']),
            'type' => $query['type'] ?? null,
            'filters' => $query['filters'] ?? [],
            'page' => $options['page'] ?? 1,
            'limit' => min(
                $options['limit'] ?? $this->config['max_results'],
                $this->config['max_results']
            ),
            'orderBy' => $options['orderBy'] ?? 'relevance',
            'direction' => $options['direction'] ?? 'desc',
            'fuzzy' => $options['fuzzy'] ?? $this->config['fuzzy_matching']
        ];
    }

    protected function executeSearch(array $parameters): array
    {
        $results = [];
        $types = $parameters['type'] 
            ? [$parameters['type'] => $this->searchableTypes[$parameters['type']]]
            : $this->searchableTypes;

        foreach ($types as $type => $handler) {
            $typeResults = $this->searchType(
                $handler,
                $parameters['term'],
                $parameters
            );
            $results = array_merge($results, $typeResults);
        }

        return $results;
    }

    protected function searchType(
        SearchableInterface $handler,
        string $term,
        array $parameters
    ): array {
        try {
            $results = $handler->search($term, $parameters);
            return $this->validateTypeResults($results);
        } catch (\Exception $e) {
            Log::error('Type search failed', [
                'type' => get_class($handler),
                'term' => $term,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    protected function processResults(array $results, array $parameters): array
    {
        // Filter results
        $results = $this->applyFilters($results, $parameters['filters']);
        
        // Calculate relevance scores
        $results = $this->calculateRelevance($results, $parameters['term']);
        
        // Sort results
        $results = $this->sortResults($results, $parameters);
        
        // Paginate results
        return $this->paginateResults($results, $parameters);
    }

    protected function applyFilters(array $results, array $filters): array
    {
        if (empty($filters)) {
            return $results;
        }

        return array_filter($results, function($result) use ($filters) {
            foreach ($filters as $field => $value) {
                if (!$this->matchesFilter($result, $field, $value)) {
                    return false;
                }
            }
            return true;
        });
    }

    protected function calculateRelevance(array $results, string $term): array
    {
        $terms = explode(' ', strtolower($term));

        foreach ($results as &$result) {
            $score = 0;
            $text = strtolower($result['content']);

            foreach ($terms as $term) {
                $count = substr_count($text, $term);
                $score += $count * (strlen($term) / strlen($text));
            }

            $result['relevance'] = min($score, 1);
        }

        return array_filter($results, function($result) {
            return $result['relevance'] >= $this->config['relevance_threshold'];
        });
    }

    protected function sortResults(array $results, array $parameters): array
    {
        $field = $parameters['orderBy'];
        $direction = $parameters['direction'];

        usort($results, function($a, $b) use ($field, $direction) {
            $comparison = $a[$field] <=> $b[$field];
            return $direction === 'desc' ? -$comparison : $comparison;
        });

        return $results;
    }

    protected function paginateResults(array $results, array $parameters): array
    {
        $offset = ($parameters['page'] - 1) * $parameters['limit'];
        return array_slice($results, $offset, $parameters['limit']);
    }

    protected function sanitizeSearchTerm(string $term): string
    {
        $term = strip_tags($term);
        $term = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $term);
        return trim($term);
    }

    protected function generateCacheKey(array $query, array $options): string
    {
        return 'search.' . md5(serialize($query) . serialize($options));
    }

    protected function cacheResults(string $key, SearchResult $result): void
    {
        $this->cache->put($key, $result, $this->config['cache_ttl']);
    }

    protected function logSearchMetrics(array $query, SearchResult $result): void
    {
        event(new SearchEvent('search_executed', [
            'query' => $query,
            'results_count' => count($result->getResults()),
            'execution_time' => $result->getExecutionTime()
        ]));
    }

    protected function handleSearchError(\Exception $e, array $query): void
    {
        Log::error('Search failed', [
            'query' => $query,
            'error' => $e->getMessage()
        ]);

        event(new SearchEvent('search_failed', [
            'query' => $query,
            'error' => $e->getMessage()
        ]));
    }
}
