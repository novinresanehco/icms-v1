<?php
namespace App\Core\Search;

class SearchManager implements SearchManagerInterface
{
    private SecurityManager $security;
    private IndexManager $index;
    private SearchRepository $repository;
    private ValidationService $validator;
    private AuditLogger $audit;

    public function search(SearchQuery $query, SecurityContext $context): SearchResults
    {
        return $this->security->executeCriticalOperation(
            new PerformSearchOperation(
                $query,
                $this->index,
                $this->repository,
                $this->validator,
                $this->audit
            ),
            $context
        );
    }

    public function index(Searchable $entity, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(
            new IndexEntityOperation(
                $entity,
                $this->index,
                $this->validator,
                $this->audit
            ),
            $context
        );
    }

    public function removeFromIndex(Searchable $entity, SecurityContext $context): void
    {
        $this->security->executeCriticalOperation(
            new RemoveFromIndexOperation(
                $entity,
                $this->index,
                $this->audit
            ),
            $context
        );
    }
}

class PerformSearchOperation extends CriticalOperation
{
    private SearchQuery $query;
    private IndexManager $index;
    private SearchRepository $repository;
    private ValidationService $validator;
    private AuditLogger $audit;

    public function execute(): SearchResults
    {
        // Validate query
        if (!$this->validator->validateSearchQuery($this->query)) {
            throw new ValidationException('Invalid search query');
        }

        // Sanitize query
        $sanitized = $this->sanitizeQuery($this->query);

        // Execute search
        $results = $this->index->search($sanitized);

        // Filter results by permissions
        $filtered = $this->filterResults($results);

        // Log search
        $this->audit->logSearch($this->query, $filtered);

        // Store search record
        $this->repository->storeSearch($this->query, $filtered);

        return $filtered;
    }

    private function sanitizeQuery(SearchQuery $query): SearchQuery
    {
        return new SearchQuery(
            $this->validator->sanitizeSearchTerm($query->term),
            $query->filters,
            $query->options
        );
    }

    private function filterResults(SearchResults $results): SearchResults
    {
        return $results->filter(function($result) {
            return $this->security->hasPermission(
                $this->context->user(),
                "search.view.{$result->type}"
            );
        });
    }

    public function getRequiredPermissions(): array
    {
        return ['search.perform'];
    }
}

class IndexEntityOperation extends CriticalOperation
{
    private Searchable $entity;
    private IndexManager $index;
    private ValidationService $validator;
    private AuditLogger $audit;

    public function execute(): void
    {
        // Validate entity
        if (!$this->validator->validateSearchable($this->entity)) {
            throw new ValidationException('Invalid searchable entity');
        }

        // Extract searchable data
        $data = $this->entity->toSearchableArray();

        // Sanitize data
        $sanitized = $this->sanitizeData($data);

        // Index data
        $this->index->index(
            $this->entity->getSearchableType(),
            $this->entity->getSearchableId(),
            $sanitized
        );

        // Log indexing
        $this->audit->logIndexing($this->entity);
    }

    private function sanitizeData(array $data): array
    {
        return array_map(
            fn($value) => $this->validator->sanitizeSearchContent($value),
            $data
        );
    }

    public function getRequiredPermissions(): array
    {
        return ['search.index'];
    }
}

class RemoveFromIndexOperation extends CriticalOperation
{
    private Searchable $entity;
    private IndexManager $index;
    private AuditLogger $audit;

    public function execute(): void
    {
        // Remove from index
        $this->index->remove(
            $this->entity->getSearchableType(),
            $this->entity->getSearchableId()
        );

        // Log removal
        $this->audit->logIndexRemoval($this->entity);
    }

    public function getRequiredPermissions(): array
    {
        return ['search.remove'];
    }
}

class IndexManager
{
    private SearchEngine $engine;
    private CacheManager $cache;

    public function search(SearchQuery $query): SearchResults
    {
        return $this->cache->remember(
            $this->getSearchCacheKey($query),
            fn() => $this->engine->search($query)
        );
    }

    public function index(string $type, int $id, array $data): void
    {
        $this->engine->index($type, $id, $data);
        $this->clearTypeCache($type);
    }

    public function remove(string $type, int $id): void
    {
        $this->engine->remove($type, $id);
        $this->clearTypeCache($type);
    }

    private function getSearchCacheKey(SearchQuery $query): string
    {
        return 'search.' . md5(serialize($query));
    }

    private function clearTypeCache(string $type): void
    {
        $this->cache->invalidatePattern("search.{$type}.*");
    }
}

class SearchEngine
{
    private array $analyzers;
    private array $filters;

    public function search(SearchQuery $query): SearchResults
    {
        // Apply analyzers
        $analyzed = $this->analyze($query->term);

        // Build search parameters
        $params = $this->buildSearchParams($analyzed, $query);

        // Execute search
        $raw = $this->executeSearch($params);

        // Apply filters
        $filtered = $this->applyFilters($raw, $query->filters);

        return new SearchResults($filtered);
    }

    private function analyze(string $term): array
    {
        $results = [];
        
        foreach ($this->analyzers as $analyzer) {
            $results = array_merge(
                $results,
                $analyzer->analyze($term)
            );
        }
        
        return array_unique($results);
    }

    private function buildSearchParams(array $terms, SearchQuery $query): array
    {
        return [
            'query' => [
                'bool' => [
                    'should' => array_map(
                        fn($term) => [
                            'multi_match' => [
                                'query' => $term,
                                'fields' => $query->fields,
                                'type' => 'most_fields',
                                'fuzziness' => 'AUTO'
                            ]
                        ],
                        $terms
                    )
                ]
            ],
            'sort' => $query->sort,
            'from' => $query->offset,
            'size' => $query->limit
        ];
    }

    private function applyFilters(array $results, array $filters): array
    {
        foreach ($this->filters as $filter) {
            $results = $filter->apply($results, $filters);
        }
        
        return $results;
    }
}

interface Searchable
{
    public function toSearchableArray(): array;
    public function getSearchableType(): string;
    public function getSearchableId(): int;
}
