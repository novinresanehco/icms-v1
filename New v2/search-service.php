<?php

namespace App\Core\Search;

use App\Core\Security\SecurityManagerInterface;
use App\Core\Cache\CacheManagerInterface;
use App\Core\Validation\ValidationServiceInterface;
use App\Core\Search\Analyzers\SearchAnalyzer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Critical Search Service with comprehensive protection
 */
class SearchService implements SearchServiceInterface
{
    private SecurityManagerInterface $security;
    private CacheManagerInterface $cache;
    private ValidationServiceInterface $validator;
    private SearchAnalyzer $analyzer;
    private array $searchFilters;
    
    public function __construct(
        SecurityManagerInterface $security,
        CacheManagerInterface $cache,
        ValidationServiceInterface $validator,
        SearchAnalyzer $analyzer,
        array $searchFilters
    ) {
        $this->security = $security;
        $this->cache = $cache;
        $this->validator = $validator;
        $this->analyzer = $analyzer;
        $this->searchFilters = $searchFilters;
    }

    /**
     * Execute protected search operation
     */
    public function search(SearchRequest $request): SearchResult
    {
        // Start monitoring
        $startTime = microtime(true);
        
        try {
            DB::beginTransaction();

            // Pre-execution validation
            $this->validateSearchRequest($request);
            
            // Check permissions
            $this->verifySearchPermissions($request);

            // Execute search with protection
            $results = $this->executeProtectedSearch($request);
            
            // Apply security filters
            $results = $this->applySecurityFilters($results, $request);
            
            // Cache valid results
            $this->cacheSearchResults($request, $results);
            
            DB::commit();
            
            // Log successful search
            $this->logSearchSuccess($request, microtime(true) - $startTime);
            
            return new SearchResult($results);

        } catch (\Exception $e) {
            DB::rollBack();
            
            // Log failure with context
            $this->logSearchFailure($request, $e);
            
            throw new SearchException(
                'Search operation failed: ' . $e->getMessage(),
                previous: $e
            );
        }
    }

    /**
     * Validate search request
     */
    private function validateSearchRequest(SearchRequest $request): void
    {
        if (!$this->validator->validate($request)) {
            throw new ValidationException('Invalid search request');
        }

        if (empty($request->query)) {
            throw new ValidationException('Search query cannot be empty');
        }

        if (strlen($request->query) > 500) {
            throw new ValidationException('Search query exceeds maximum length');
        }
    }

    /**
     * Verify search permissions
     */
    private function verifySearchPermissions(SearchRequest $request): void
    {
        if (!$this->security->hasPermission($request->user, 'search.execute')) {
            throw new UnauthorizedException('Unauthorized to perform search');
        }

        foreach ($request->types as $type) {
            if (!$this->security->hasPermission($request->user, "search.type.$type")) {
                throw new UnauthorizedException("Unauthorized to search type: $type");
            }
        }
    }

    /**
     * Execute search with protection
     */
    private function executeProtectedSearch(SearchRequest $request): array
    {
        // Check cache first
        $cacheKey = $this->buildCacheKey($request);
        $cached = $this->cache->get($cacheKey);
        
        if ($cached !== null) {
            return $cached;
        }

        // Analyze search terms
        $terms = $this->analyzer->analyze($request->query);
        
        if (empty($terms)) {
            throw new SearchException('No valid search terms after analysis');
        }

        // Execute search
        $results = $this->performSearch($terms, $request);
        
        // Apply filters
        foreach ($this->searchFilters as $filter) {
            $results = $filter->apply($results, $request->filters);
        }

        return $results;
    }

    /**
     * Apply security filters to results
     */
    private function applySecurityFilters(array $results, SearchRequest $request): array
    {
        return array_filter($results, function($result) use ($request) {
            try {
                return $this->security->hasPermission(
                    $request->user,
                    "search.view.{$result['type']}.{$result['id']}"
                );
            } catch (\Exception $e) {
                Log::error('Error checking search result permission', [
                    'result' => $result,
                    'error' => $e->getMessage()
                ]);
                return false;
            }
        });
    }

    /**
     * Cache search results
     */
    private function cacheSearchResults(SearchRequest $request, array $results): void
    {
        $cacheKey = $this->buildCacheKey($request);
        $this->cache->set($cacheKey, $results, 3600); // 1 hour cache
    }

    /**
     * Build cache key for request
     */
    private function buildCacheKey(SearchRequest $request): string
    {
        return sprintf(
            'search:%s:%s:%s',
            md5($request->query),
            implode(',', $request->types),
            $request->user->id
        );
    }

    /**
     * Perform actual search
     */
    private function performSearch(array $terms, SearchRequest $request): array
    {
        // Implementation depends on search backend
        // This is just a placeholder
        return [];
    }

    /**
     * Log successful search
     */
    private function logSearchSuccess(SearchRequest $request, float $duration): void
    {
        Log::info('Search completed successfully', [
            'query' => $request->query,
            'user' => $request->user->id,
            'duration' => $duration,
            'types' => $request->types
        ]);
    }

    /**
     * Log search failure
     */
    private function logSearchFailure(SearchRequest $request, \Exception $e): void
    {
        Log::error('Search operation failed', [
            'query' => $request->query,
            'user' => $request->user->id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
