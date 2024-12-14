<?php

namespace App\Core\Search;

use Illuminate\Support\Facades\{DB, Cache};
use App\Core\Security\SecurityManager;
use App\Core\Services\{ValidationService, IndexingService};
use App\Core\Interfaces\SearchManagerInterface;
use App\Core\Exceptions\{SearchException, ValidationException};

class SearchManager implements SearchManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private IndexingService $indexer;
    private array $config;
    private array $indexCache = [];

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        IndexingService $indexer,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->indexer = $indexer;
        $this->config = $config;
    }

    public function search(string $query, array $options = []): SearchResult
    {
        return $this->security->executeSecureOperation(
            fn() => $this->executeSearch($query, $options),
            new SecurityContext('search.query', ['query' => $query])
        );
    }

    public function indexContent(array $content): bool
    {
        return $this->security->executeSecureOperation(
            fn() => $this->processIndexing($content),
            new SecurityContext('search.index', ['content' => $content])
        );
    }

    public function updateIndex(string $identifier, array $content): bool
    {
        return $this->security->executeSecureOperation(
            fn() => $this->processIndexUpdate($identifier, $content),
            new SecurityContext('search.update', ['identifier' => $identifier])
        );
    }

    protected function executeSearch(string $query, array $options): SearchResult
    {
        try {
            $this->validateSearchQuery($query);
            $sanitizedQuery = $this->sanitizeQuery($query);
            
            $cacheKey = $this->generateSearchCacheKey($sanitizedQuery, $options);
            if ($cachedResult = $this->getFromCache($cacheKey)) {
                return $cachedResult;
            }
            
            $searchParams = $this->buildSearchParams($sanitizedQuery, $options);
            $result = $this->performSearch($searchParams);
            
            $processedResult = $this->processSearchResult($result);
            $this->cacheSearchResult($cacheKey, $processedResult);
            
            return $processedResult;

        } catch (\Exception $e) {
            $this->handleSearchFailure($query, $e);
            throw new SearchException('Search execution failed: ' . $e->getMessage());
        }
    }

    protected function processIndexing(array $content): bool
    {
        DB::beginTransaction();
        try {
            $this->validateContent($content);
            
            $processedContent = $this->preprocessContent($content);
            $indexData = $this->extractIndexData($processedContent);
            
            $this->indexer->index($indexData);
            $this->updateIndexMetadata($content['identifier']);
            
            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleIndexingFailure($content, $e);
            throw new SearchException('Content indexing failed: ' . $e->getMessage());
        }
    }

    protected function processIndexUpdate(string $identifier, array $content): bool
    {
        DB::beginTransaction();
        try {
            $this->validateContent($content);
            $this->validateIdentifier($identifier);
            
            $existingIndex = $this->getExistingIndex($identifier);
            if (!$existingIndex) {
                throw new SearchException('Index not found');
            }
            
            $processedContent = $this->preprocessContent($content);
            $updatedIndexData = $this->mergeIndexData($existingIndex, $processedContent);
            
            $this->indexer->update($identifier, $updatedIndexData);
            $this->clearIndexCache($identifier);
            
            DB::commit();
            return true;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->handleUpdateFailure($identifier, $e);
            throw new SearchException('Index update failed: ' . $e->getMessage());
        }
    }

    protected function validateSearchQuery(string $query): void
    {
        if (strlen($query) < $this->config['min_query_length']) {
            throw new ValidationException('Query too short');
        }

        if (!$this->validator->validateSearchQuery($query)) {
            throw new ValidationException('Invalid search query');
        }
    }

    protected function validateContent(array $content): void
    {
        if (!isset($content['identifier'])) {
            throw new ValidationException('Content identifier missing');
        }

        if (!$this->validator->validateIndexContent($content)) {
            throw new ValidationException('Invalid content format');
        }
    }

    protected function sanitizeQuery(string $query): string
    {
        $query = strip_tags($query);
        $query = preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $query);
        return trim($query);
    }

    protected function buildSearchParams(string $query, array $options): array
    {
        return [
            'query' => $query,
            'fields' => $options['fields'] ?? ['title', 'content'],
            'filters' => $this->processFilters($options['filters'] ?? []),
            'page' => max(1, $options['page'] ?? 1),
            'limit' => min($options['limit'] ?? 10, $this->config['max_results']),
            'sort' => $this->validateSortOptions($options['sort'] ?? [])
        ];
    }

    protected function processFilters(array $filters): array
    {
        $processedFilters = [];
        foreach ($filters as $field => $value) {
            if ($this->isValidFilter($field, $value)) {
                $processedFilters[$field] = $this->sanitizeFilterValue($value);
            }
        }
        return $processedFilters;
    }

    protected function validateSortOptions(array $sort): array
    {
        $validSort = [];
        foreach ($sort as $field => $direction) {
            if ($this->isValidSortField($field) && $this->isValidSortDirection($direction)) {
                $validSort[$field] = strtoupper($direction);
            }
        }
        return $validSort;
    }

    protected function performSearch(array $params): array
    {
        $builder = $this->createSearchBuilder($params);
        return $builder->get();
    }

    protected function processSearchResult(array $result): SearchResult
    {
        $processedItems = array_map([$this, 'processResultItem'], $result['items']);
        
        return new SearchResult(
            items: $processedItems,
            total: $result['total'],
            page: $result['page'],
            hasMore: $result['has_more']
        );
    }

    protected function preprocessContent(array $content): array
    {
        $processed = [];
        foreach ($content as $field => $value) {
            if ($this->isIndexableField($field)) {
                $processed[$field] = $this->processFieldContent($field, $value);
            }
        }
        return $processed;
    }

    protected function processFieldContent(string $field, mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->processTextContent($value);
        }
        
        if (is_array($value)) {
            return array_map([$this, 'processFieldContent'], $value);
        }
        
        return $value;
    }

    protected function processTextContent(string $content): string
    {
        $content = strip_tags($content);
        $content = preg_replace('/\s+/', ' ', $content);
        return trim($content);
    }

    protected function clearIndexCache(string $identifier): void
    {
        unset($this->indexCache[$identifier]);
        Cache::tags(['search', $identifier])->flush();
    }
}
