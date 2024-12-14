<?php

namespace App\Core\Search;

use App\Core\Security\CoreSecurityManager;
use App\Core\Services\ValidationService;
use App\Core\Services\AuditService;
use App\Core\Exceptions\SearchException;
use Illuminate\Support\Facades\Cache;

class SearchManager
{
    private CoreSecurityManager $security;
    private ValidationService $validator;
    private AuditService $audit;
    private array $config;

    private const CACHE_TTL = 3600;
    private const MAX_SEARCH_RESULTS = 1000;
    private const MIN_TERM_LENGTH = 3;

    public function __construct(
        CoreSecurityManager $security,
        ValidationService $validator,
        AuditService $audit,
        array $config
    ) {
        $this->security = $security;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->config = $config;
    }

    public function search(array $query): SearchResult
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeSearch($query),
            ['operation' => 'search_execute', 'query' => $this->sanitizeQuery($query)]
        );
    }

    public function index(array $document): bool
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeIndexing($document),
            ['operation' => 'document_index', 'document_id' => $document['id']]
        );
    }

    public function reindex(string $type = null): IndexResult
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeReindex($type),
            ['operation' => 'reindex', 'type' => $type]
        );
    }

    private function executeSearch(array $query): SearchResult
    {
        try {
            // Validate and prepare query
            $this->validateSearchQuery($query);
            $preparedQuery = $this->prepareSearchQuery($query);

            // Check cache
            $cacheKey = $this->getSearchCacheKey($preparedQuery);
            if ($cached = Cache::get($cacheKey)) {
                return $this->validateCachedResults($cached);
            }

            // Execute search
            $results = $this->performSearch($preparedQuery);

            // Process results
            $processedResults = $this->processSearchResults($results);

            // Cache results
            $this->cacheSearchResults($cacheKey, $processedResults);

            return new SearchResult([
                'results' => $processedResults,
                'total' => count($results),
                'query' => $preparedQuery,
                'execution_time' => $this->measureExecutionTime()
            ]);

        } catch (\Exception $e) {
            throw new SearchException('Search failed: ' . $e->getMessage());
        }
    }

    private function executeIndexing(array $document): bool
    {
        try {
            // Validate document
            $this->validateDocument($document);

            // Prepare document for indexing
            $preparedDocument = $this->prepareDocument($document);

            // Update search index
            $this->updateSearchIndex($preparedDocument);

            // Update related indices
            $this->updateRelatedIndices($preparedDocument);

            // Clear related caches
            $this->clearRelatedCaches($document['id']);

            return true;

        } catch (\Exception $e) {
            throw new SearchException('Indexing failed: ' . $e->getMessage());
        }
    }

    private function executeReindex(?string $type): IndexResult
    {
        try {
            // Get documents to reindex
            $documents = $this->getDocumentsForReindex($type);

            $results = [
                'successful' => 0,
                'failed' => 0,
                'errors' => []
            ];

            // Process documents in batches
            foreach (array_chunk($documents, 100) as $batch) {
                try {
                    // Process batch
                    $this->processBatch($batch);
                    $results['successful'] += count($batch);

                } catch (\Exception $e) {
                    $results['failed'] += count($batch);
                    $results['errors'][] = $e->getMessage();
                }

                // Allow other operations to proceed
                if ($this->shouldYield()) {
                    $this->yieldControl();
                }
            }

            // Generate reindex report
            return new IndexResult([
                'total' => count($documents),
                'successful' => $results['successful'],
                'failed' => $results['failed'],
                'errors' => $results['errors']
            ]);

        } catch (\Exception $e) {
            throw new SearchException('Reindex failed: ' . $e->getMessage());
        }
    }

    private function validateSearchQuery(array $query): void
    {
        $rules = [
            'term' => ['required', 'string', "min:" . self::MIN_TERM_LENGTH],
            'type' => ['sometimes', 'string'],
            'filters' => ['sometimes', 'array'],
            'sort' => ['sometimes', 'string', 'in:relevance,date,title'],
            'limit' => ['sometimes', 'integer', 'max:' . self::MAX_SEARCH_RESULTS]
        ];

        if (!$this->validator->validateData($query, $rules)) {
            throw new SearchException('Invalid search query');
        }
    }

    private function prepareSearchQuery(array $query): array
    {
        // Sanitize search term
        $query['term'] = $this->sanitizeSearchTerm($query['term']);

        // Apply default values
        $query['sort'] = $query['sort'] ?? 'relevance';
        $query['limit'] = min($query['limit'] ?? self::MAX_SEARCH_RESULTS, self::MAX_SEARCH_RESULTS);

        // Prepare filters
        if (isset($query['filters'])) {
            $query['filters'] = $this->prepareSearchFilters($query['filters']);
        }

        return $query;
    }

    private function validateDocument(array $document): void
    {
        $rules = [
            'id' => 'required|string',
            'type' => 'required|string',
            'title' => 'required|string',
            'content' => 'required|string',
            'metadata' => 'sometimes|array'
        ];

        if (!$this->validator->validateData($document, $rules)) {
            throw new SearchException('Invalid document format');
        }
    }

    private function prepareDocument(array $document): array
    {
        return [
            'id' => $document['id'],
            'type' => $document['type'],
            'title' => $this->normalizeText($document['title']),
            'content' => $this->normalizeText($document['content']),
            'metadata' => $this->prepareMetadata($document['metadata'] ?? []),
            'indexed_at' => now()->toIso8601String(),
            'checksum' => $this->calculateDocumentChecksum($document)
        ];
    }

    private function performSearch(array $query): array
    {
        $results = [];
        
        // Search by type if specified
        if (isset($query['type'])) {
            $results = $this->searchByType($query);
        } else {
            $results = $this->searchAllTypes($query);
        }

        // Apply filters
        if (isset($query['filters'])) {
            $results = $this->applySearchFilters($results, $query['filters']);
        }

        // Sort results
        $results = $this->sortSearchResults($results, $query['sort']);

        // Apply limit
        return array_slice($results, 0, $query['limit']);
    }

    private function processSearchResults(array $results): array
    {
        return array_map(function ($result) {
            return [
                'id' => $result['id'],
                'type' => $result['type'],
                'title' => $result['title'],
                'excerpt' => $this->generateExcerpt($result['content']),
                'score' => $result['score'],
                'metadata' => $result['metadata']
            ];
        }, $results);
    }

    private function updateSearchIndex(array $document): void
    {
        // Store document in search index
        SearchIndex::updateOrCreate(
            ['document_id' => $document['id']],
            [
                'type' => $document['type'],
                'title' => $document['title'],
                'content' => $document['content'],
                'metadata' => $document['metadata'],
                'indexed_at' => $document['indexed_at'],
                'checksum' => $document['checksum']
            ]
        );

        // Update search terms
        $this->updateSearchTerms($document);

        // Update type-specific index
        $this->updateTypeIndex($document);
    }

    private function updateRelatedIndices(array $document): void
    {
        // Update related documents
        $this->updateRelatedDocuments($document);

        // Update category indices
        if (isset($document['metadata']['categories'])) {
            $this->updateCategoryIndices($document);
        }

        // Update tag indices
        if (isset($document['metadata']['tags'])) {
            $this->updateTagIndices($document);
        }
    }

    private function clearRelatedCaches(string $documentId): void
    {
        Cache::tags(['search'])->forget("document:{$documentId}");
        Cache::tags(['search', 'related'])->forget("related:{$documentId}");
    }

    private function getDocumentsForReindex(?string $type): array
    {
        if ($type) {
            return $this->getDocumentsByType($type);
        }
        return $this->getAllDocuments();
    }

    private function processBatch(array $batch): void
    {
        foreach ($batch as $document) {
            $this->index($document);
        }
    }

    private function shouldYield(): bool
    {
        return memory_get_usage() > $this->config['memory_limit'] ||
               $this->getExecutionTime() > $this->config['time_limit'];
    }

    private function yieldControl(): void
    {
        if (function_exists('yield_control')) {
            yield_control();
        }
    }

    private function sanitizeSearchTerm(string $term): string
    {
        return preg_replace('/[^\p{L}\p{N}\s-]/u', '', $term);
    }

    private function prepareSearchFilters(array $filters): array
    {
        return array_filter($filters, function ($filter) {
            return isset($filter['field']) && isset($filter['value']);
        });
    }

    private function normalizeText(string $text): string
    {
        return mb_strtolower(trim($text));
    }

    private function prepareMetadata(array $metadata): array
    {
        return array_filter($metadata, function ($value) {
            return !is_null($value) && $value !== '';
        });
    }

    private function calculateDocumentChecksum(array $document): string
    {
        return hash('sha256', json_encode([
            $document['title'],
            $document['content'],
            $document['metadata'] ?? []
        ]));
    }

    private function searchByType(array $query): array
    {
        return SearchIndex::where('type', $query['type'])
                         ->whereFullText(['title', 'content'], $query['term'])
                         ->get()
                         ->toArray();
    }

    private function searchAllTypes(array $query): array
    {
        return SearchIndex::whereFullText(['title', 'content'], $query['term'])
                         ->get()
                         ->toArray();
    }

    private function applySearchFilters(array $results, array $filters): array
    {
        return array_filter($results, function ($result) use ($filters) {
            foreach ($filters as $filter) {
                if (!$this->matchesFilter($result, $filter)) {
                    return false;
                }
            }
            return true;
        });
    }

    private function sortSearchResults(array $results, string $sort): array
    {
        switch ($sort) {
            case 'date':
                usort($results, fn($a, $b) => $b['indexed_at'] <=> $a['indexed_at']);
                break;
            case 'title':
                usort($results, fn($a, $b) => $a['title'] <=> $b['title']);
                break;
            default:
                usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
        }
        return $results;
    }

    private function generateExcerpt(string $content, int $length = 200): string
    {
        return mb_substr(strip_tags($content), 0, $length) . '...';
    }

    private function updateSearchTerms(array $document): void
    {
        $terms = $this->extractSearchTerms($document);
        
        foreach ($terms as $term) {
            SearchTerm::updateOrCreate(
                ['term' => $term],
                [
                    'frequency' => DB::raw('frequency + 1'),
                    'last_seen' => now()
                ]
            );
        }
    }

    private function updateTypeIndex(array $document): void
    {
        TypeIndex::updateOrCreate(
            [
                'type' => $document['type'],
                'document_id' => $document['id']
            ],
            [
                'metadata' => $document['metadata'],
                'indexed_at' => $document['indexed_at']
            ]
        );
    }

    private function extractSearchTerms(array $document): array
    {
        $text = $document['title'] . ' ' . $document['content'];
        $words = str_word_count($text, 1);
        return array_unique($words);
    }

    private function matchesFilter(array $result, array $filter): bool
    {
        $value = $result[$filter['field']] ?? null;
        return $value === $filter['value'];
    }

    private function getSearchCacheKey(array $query): string
    {
        return 'search:' . md5(serialize($query));
    }

    private function validateCachedResults($cached): SearchResult
    {
        if (!isset($cached['results'], $cached['total'])) {
            throw new SearchException('Invalid cached results');
        }
        return new SearchResult($cached);
    }

    private function cacheSearchResults(string $key, array $results): void
    {
        Cache::tags(['search'])->put($key, $results, self::CACHE_TTL);
    }

    private function getExecutionTime(): float
    {
        return microtime(true) - LARAVEL_START;
    }

    private function measureExecutionTime(): float
    {
        return round((microtime(true) - LARAVEL_START) * 1000, 2);
    }
}