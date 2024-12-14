namespace App\Core\Search;

class SearchManager implements SearchInterface 
{
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private IndexManager $index;
    private FilterManager $filter;
    private array $config;

    public function search(SearchRequest $request): SearchResult 
    {
        return $this->security->executeCriticalOperation(
            new SearchOperation($request),
            function() use ($request) {
                // Validate request
                $this->validateRequest($request);
                
                // Get cache key
                $cacheKey = $this->getCacheKey($request);
                
                // Return cached if exists
                return $this->cache->remember($cacheKey, function() use ($request) {
                    // Apply security filters
                    $request = $this->applySecurityFilters($request);
                    
                    // Execute search
                    $results = $this->executeSearch($request);
                    
                    // Apply post filters
                    $results = $this->applyPostFilters($results, $request);
                    
                    // Format results
                    return $this->formatResults($results, $request);
                });
            }
        );
    }

    public function buildIndex(string $type): void 
    {
        $this->security->executeCriticalOperation(
            new BuildIndexOperation($type),
            function() use ($type) {
                // Validate type
                $this->validateIndexType($type);
                
                // Get indexable items
                $items = $this->getIndexableItems($type);
                
                // Process items in chunks
                foreach ($items->chunk(100) as $chunk) {
                    try {
                        // Index chunk
                        $this->indexChunk($chunk, $type);
                        
                    } catch (\Exception $e) {
                        // Log error but continue
                        $this->logIndexError($e, $type, $chunk);
                    }
                }
                
                // Optimize index
                $this->optimizeIndex($type);
            }
        );
    }

    public function reindex(string $type, array $ids): void 
    {
        $this->security->executeCriticalOperation(
            new ReindexOperation($type, $ids),
            function() use ($type, $ids) {
                // Validate inputs
                $this->validateReindexRequest($type, $ids);
                
                // Remove old entries
                $this->removeFromIndex($type, $ids);
                
                // Get fresh items
                $items = $this->getFreshItems($type, $ids);
                
                // Reindex items
                $this->indexItems($items, $type);
                
                // Clear related caches
                $this->clearRelatedCaches($type, $ids);
            }
        );
    }

    protected function validateRequest(SearchRequest $request): void 
    {
        if (!$this->validator->isValidSearchRequest($request)) {
            throw new InvalidSearchRequestException();
        }

        if ($this->exceedsRateLimit($request)) {
            throw new SearchRateLimitException();
        }
    }

    protected function applySecurityFilters(SearchRequest $request): SearchRequest 
    {
        // Apply access control filters
        $request = $this->applyAccessFilters($request);
        
        // Apply content security filters
        $request = $this->applyContentFilters($request);
        
        return $request;
    }

    protected function executeSearch(SearchRequest $request): Collection 
    {
        // Build query
        $query = $this->buildSearchQuery($request);
        
        // Apply type-specific handlers
        $query = $this->applyTypeHandlers($query, $request);
        
        // Execute search
        return $this->index->search($query);
    }

    protected function buildSearchQuery(SearchRequest $request): SearchQuery 
    {
        return (new SearchQueryBuilder())
            ->setType($request->getType())
            ->setKeywords($this->sanitizeKeywords($request->getKeywords()))
            ->setFilters($request->getFilters())
            ->setSorting($request->getSorting())
            ->setPagination($request->getPagination())
            ->setHighlighting($request->getHighlighting())
            ->build();
    }

    protected function applyPostFilters(Collection $results, SearchRequest $request): Collection 
    {
        foreach ($this->config['post_filters'] as $filter) {
            $results = $filter->apply($results, $request);
        }
        
        return $results;
    }

    protected function formatResults(Collection $results, SearchRequest $request): SearchResult 
    {
        // Format items
        $items = $this->formatItems($results, $request);
        
        // Add metadata
        $metadata = $this->buildResultMetadata($results, $request);
        
        // Add facets if requested
        if ($request->wantsFacets()) {
            $metadata['facets'] = $this->buildFacets($results, $request);
        }
        
        return new SearchResult($items, $metadata);
    }

    protected function sanitizeKeywords(string $keywords): string 
    {
        // Remove dangerous characters
        $keywords = $this->filter->sanitize($keywords);
        
        // Apply word filters
        $keywords = $this->filter->filterWords($keywords);
        
        return $keywords;
    }

    protected function optimizeIndex(string $type): void 
    {
        $this->index->optimize([
            'type' => $type,
            'segments' => $this->config['index_segments'],
            'buffer_size' => $this->config['index_buffer']
        ]);
    }

    protected function getCacheKey(SearchRequest $request): string 
    {
        return sprintf(
            'search.%s.%s.%s',
            $request->getType(),
            md5($request->getKeywords()),
            md5(serialize($request->getFilters()))
        );
    }
}
