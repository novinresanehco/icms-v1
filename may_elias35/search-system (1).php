<?php

namespace App\Core\Search;

use App\Core\Security\SecurityManager;
use App\Core\Validation\ValidationService;
use App\Core\Cache\CacheManager;
use Illuminate\Support\Facades\DB;

class SearchManager implements SearchManagerInterface
{
    private SecurityManager $security;
    private ValidationService $validator;
    private CacheManager $cache;
    private IndexManager $indexManager;
    private array $config;

    private const MAX_SEARCH_RESULTS = 1000;
    private const CACHE_TTL = 3600;

    public function __construct(
        SecurityManager $security,
        ValidationService $validator,
        CacheManager $cache,
        IndexManager $indexManager,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->cache = $cache;
        $this->indexManager = $indexManager;
        $this->config = $config;
    }

    public function search(array $query, array $options = []): SearchResponse
    {
        return $this->security->executeSecureOperation(function() use ($query, $options) {
            $this->validateSearchQuery($query);
            
            $cacheKey = $this->generateCacheKey($query, $options);
            
            return $this->cache->remember($cacheKey, self::CACHE_TTL, function() use ($query, $options) {
                // Prepare search
                $preparedQuery = $this->prepareQuery($query);
                
                // Execute search
                $results = $this->executeSearch($preparedQuery, $options);
                
                // Process results
                $processed = $this->processResults($results);
                
                // Apply security filters
                $filtered = $this->applySecurityFilters($processed);
                
                return new SearchResponse($filtered);
            });
        }, ['operation' => 'search_execute']);
    }

    public function index(string $type, array $data): IndexResponse
    {
        return $this->security->executeSecureOperation(function() use ($type, $data) {
            $this->validateIndexData($type, $data);
            
            DB::beginTransaction();
            try {
                // Process data for indexing
                $processed = $this->processIndexData($type, $data);
                
                // Create/update index
                $index = $this->indexManager->updateIndex($type, $processed);
                
                // Update search mappings
                $this->updateSearchMappings($type, $index);
                
                // Generate metadata
                $this->generateIndexMetadata($index);
                
                DB::commit();
                
                // Invalidate relevant caches
                $this->invalidateSearchCaches($type);
                
                return new IndexResponse($index);
                
            } catch (\Exception $e) {
                DB::rollBack();
                throw new IndexingException('Failed to index data: ' . $e->getMessage(), 0, $e);
            }
        }, ['operation' => 'index_update']);
    }

    private function validateSearchQuery(array $query): void
    {
        $rules = [
            'term' => ['required', 'string', 'min:2'],
            'type' => ['required', 'string', 'in:' . implode(',', $this->config['searchable_types'])],
            'filters' => ['array'],
            'sort' => ['array']
        ];

        if (!$this->validator->validate($query, $rules)) {
            throw new ValidationException('Invalid search query');
        }

        $this->validateSearchSecurity($query);
    }

    private function prepareQuery(array $query): array
    {
        return [
            'term' => $this->sanitizeSearchTerm($query['term']),
            'type' => $query['type'],
            'filters' => $this->prepareFilters($query['filters'] ?? []),
            'sort' => $this->prepareSortCriteria($query['sort'] ?? [])
        ];
    }

    private function executeSearch(array $query, array $options): array
    {
        $builder = $this->createSearchBuilder($query);
        
        // Apply filters
        foreach ($query['filters'] as $filter) {
            $builder->applyFilter($filter);
        }
        
        // Apply sorting
        foreach ($query['sort'] as $sort) {
            $builder->applySort($sort);
        }
        
        // Apply pagination
        $builder->limit($options['limit'] ?? self::MAX_SEARCH_RESULTS);
        $builder->offset($options['offset'] ?? 0);
        
        return $builder->execute();
    }

    private function processResults(array $results): array
    {
        $processed = [];
        
        foreach ($results as $result) {
            $processed[] = [
                'id' => $result['id'],
                'type' => $result['type'],
                'score' => $result['score'],
                'highlights' => $this->generateHighlights($result),
                'data' => $this->processResultData($result['data'])
            ];
        }
        
        return $processed;
    }

    private function applySecurityFilters(array $results): array
    {
        return array_filter($results, function($result) {
            return $this->security->canAccess($result['type'], $result['id']);
        });
    }

    private function validateIndexData(string $type, array $data): void
    {
        if (!in_array($type, $this->config['indexable_types'])) {
            throw new ValidationException('Invalid index type');
        }

        $rules = $this->config['index_validation_rules'][$type] ?? [];
        
        if (!$this->validator->validate($data, $rules)) {
            throw new ValidationException('Invalid index data');
        }
    }

    private function processIndexData(string $type, array $data): array
    {
        return [
            'content' => $this->prepareContent($data),
            'metadata' => $this->prepareMetadata($type, $data),
            'timestamps' => $this->generateTimestamps()
        ];
    }

    private function prepareContent(array $data): array
    {
        $content = [];
        
        foreach ($this->config['indexable_fields'] as $field) {
            if (isset($data[$field])) {
                $content[$field] = $this->processField($field, $data[$field]);
            }
        }
        
        return $content;
    }

    private function prepareMetadata(string $type, array $data): array
    {
        return [
            'type' => $type,
            'status' => $data['status'] ?? 'active',
            'visibility' => $data['visibility'] ?? 'public',
            'permissions' => $data['permissions'] ?? [],
            'checksum' => $this->generateChecksum($data)
        ];
    }

    private function generateHighlights(array $result): array
    {
        $highlights = [];
        
        foreach ($result['matches'] as $field => $matches) {
            $highlights[$field] = $this->processHighlights($matches);
        }
        
        return $highlights;
    }

    private function processHighlights(array $matches): array
    {
        return array_map(function($match) {
            return [
                'text' => $match['text'],
                'position' => $match['position'],
                'length' => $match['length']
            ];
        }, $matches);
    }

    private function invalidateSearchCaches(string $type): void
    {
        $this->cache->invalidate([
            "search:{$type}:*",
            "index:{$type}:*",
            'search:counts',
            'search:mappings'
        ]);
    }
}
