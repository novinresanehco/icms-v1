<?php

namespace App\Core\Search;

use Illuminate\Support\Facades\{DB, Cache, Log};
use App\Core\Security\SecurityManager;
use App\Core\Exceptions\SearchException;

class SearchManager
{
    private SecurityManager $security;
    private SearchIndex $index;
    private QueryParser $parser;
    private ResultValidator $validator;
    private AccessControl $accessControl;
    private AuditLogger $auditLogger;

    public function search(SearchRequest $request): SearchResult
    {
        return $this->security->executeCriticalOperation(
            fn() => $this->executeSearch($request),
            $request->getContext()
        );
    }

    private function executeSearch(SearchRequest $request): SearchResult
    {
        $this->validateRequest($request);
        
        $query = $this->parser->parse($request);
        $this->validateQuery($query);
        
        $cacheKey = $this->getCacheKey($query);
        
        return Cache::tags(['search'])->remember($cacheKey, 300, function() use ($query, $request) {
            $results = $this->index->search($query);
            $filtered = $this->filterResults($results, $request);
            $validated = $this->validateResults($filtered);
            
            $this->auditLogger->logSearch($request, $validated);
            
            return new SearchResult($validated);
        });
    }

    public function indexContent(array $content, array $context): void
    {
        $this->security->executeCriticalOperation(
            fn() => $this->executeIndexing($content),
            $context
        );
    }

    private function executeIndexing(array $content): void
    {
        DB::transaction(function() use ($content) {
            $sanitized = $this->sanitizeContent($content);
            $indexed = $this->index->indexContent($sanitized);
            
            $this->validateIndexing($indexed);
            $this->updateSearchMetadata($indexed);
            
            $this->auditLogger->logIndexing($indexed);
        });
    }

    private function validateRequest(SearchRequest $request): void
    {
        if (!$this->validator->validateRequest($request)) {
            throw new SearchException('Invalid search request');
        }

        if (!$this->accessControl->checkPermissions($request)) {
            throw new SearchException('Unauthorized search request');
        }
    }

    private function validateQuery(SearchQuery $query): void
    {
        if (!$this->validator->validateQuery($query)) {
            throw new SearchException('Invalid query format');
        }

        if ($query->exceedsComplexity()) {
            throw new SearchException('Query too complex');
        }
    }

    private function filterResults(array $results, SearchRequest $request): array
    {
        return array_filter($results, function($result) use ($request) {
            return $this->accessControl->canAccess($request->getUser(), $result) &&
                   $this->validateResultItem($result);
        });
    }

    private function validateResults(array $results): array
    {
        foreach ($results as $result) {
            if (!$this->validator->validateResult($result)) {
                $this->auditLogger->logInvalidResult($result);
                throw new SearchException('Invalid search result detected');
            }
        }
        return $results;
    }

    private function sanitizeContent(array $content): array
    {
        return array_map(function($item) {
            return [
                'id' => $item['id'],
                'content' => $this->sanitizeText($item['content']),
                'metadata' => $this->sanitizeMetadata($item['metadata']),
                'permissions' => $item['permissions'],
                'type' => $item['type'],
                'timestamp' => now()
            ];
        }, $content);
    }

    private function validateIndexing(array $indexed): void
    {
        foreach ($indexed as $item) {
            if (!$this->validator->validateIndexedItem($item)) {
                throw new SearchException('Index validation failed');
            }
        }
    }

    private function updateSearchMetadata(array $indexed): void
    {
        $metadata = [
            'last_index' => now(),
            'total_documents' => count($indexed),
            'index_status' => 'complete'
        ];

        Cache::tags(['search', 'metadata'])->put('search_metadata', $metadata, 3600);
    }

    private function sanitizeText(string $text): string
    {
        $text = strip_tags($text);
        $text = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $text);
        return trim($text);
    }

    private function sanitizeMetadata(array $metadata): array
    {
        return array_filter($metadata, function($key, $value) {
            return $this->isAllowedMetadata($key) && 
                   $this->isValidMetadataValue($value);
        }, ARRAY_FILTER_USE_BOTH);
    }

    private function validateResultItem($result): bool
    {
        return isset($result['id']) &&
               isset($result['content']) &&
               isset($result['type']) &&
               $this->isValidContentType($result['type']);
    }

    private function getCacheKey(SearchQuery $query): string
    {
        return sprintf(
            'search:%s:%s',
            $query->getHash(),
            $query->getFilters()->getHash()
        );
    }

    private function isAllowedMetadata(string $key): bool
    {
        return in_array($key, [
            'title',
            'author',
            'tags',
            'category',
            'published_at'
        ]);
    }

    private function isValidContentType(string $type): bool
    {
        return in_array($type, [
            'page',
            'post',
            'article',
            'document'
        ]);
    }
}
