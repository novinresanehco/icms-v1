<?php

namespace App\Core\Search;

class SearchEngine
{
    private IndexManager $indexManager;
    private QueryBuilder $queryBuilder;
    private SearchAnalyzer $analyzer;
    private array $filters = [];

    public function search(SearchQuery $query): SearchResult
    {
        $analyzedQuery = $this->analyzer->analyze($query);
        $queryParams = $this->queryBuilder->build($analyzedQuery);
        $results = $this->indexManager->search($queryParams);

        return new SearchResult(
            $results->getItems(),
            $results->getTotal(),
            $results->getFacets(),
            $analyzedQuery->getSuggestions()
        );
    }

    public function index(Searchable $document): void
    {
        $analyzedDocument = $this->analyzer->analyzeDocument($document);
        $this->indexManager->indexDocument($analyzedDocument);
    }

    public function addFilter(string $name, SearchFilter $filter): void
    {
        $this->filters[$name] = $filter;
    }
}

class IndexManager
{
    private $client;
    private string $indexName;
    private array $settings;

    public function indexDocument(AnalyzedDocument $document): void
    {
        $this->client->index([
            'index' => $this->indexName,
            'id' => $document->getId(),
            'body' => $document->toArray()
        ]);
    }

    public function search(array $params): SearchResults
    {
        $response = $this->client->search([
            'index' => $this->indexName,
            'body' => $params
        ]);

        return $this->parseResponse($response);
    }

    public function deleteDocument(string $id): void
    {
        $this->client->delete([
            'index' => $this->indexName,
            'id' => $id
        ]);
    }

    public function optimize(): void
    {
        $this->client->indices()->forcemerge([
            'index' => $this->indexName
        ]);
    }

    private function parseResponse(array $response): SearchResults
    {
        $items = array_map(
            fn($hit) => $this->parseHit($hit),
            $response['hits']['hits']
        );

        return new SearchResults(
            $items,
            $response['hits']['total']['value'],
            $response['aggregations'] ?? []
        );
    }
}

class QueryBuilder
{
    private array $fields;
    private array $boosts;

    public function build(AnalyzedQuery $query): array
    {
        $must = $this->buildMustClauses($query);
        $filter = $this->buildFilterClauses($query);
        $sort = $this->buildSortClauses($query);

        return [
            'query' => [
                'bool' => [
                    'must' => $must,
                    'filter' => $filter
                ]
            ],
            'sort' => $sort,
            'from' => $query->getOffset(),
            'size' => $query->getLimit(),
            'aggs' => $this->buildAggregations($query)
        ];
    }

    private function buildMustClauses(AnalyzedQuery $query): array
    {
        $clauses = [];

        if ($query->getSearchText()) {
            $clauses[] = [
                'multi_match' => [
                    'query' => $query->getSearchText(),
                    'fields' => array_map(
                        fn($field) => $field . '^' . ($this->boosts[$field] ?? 1),
                        $this->fields
                    ),
                    'type' => 'most_fields'
                ]
            ];
        }

        return $clauses;
    }

    private function buildFilterClauses(AnalyzedQuery $query): array
    {
        $filters = [];

        foreach ($query->getFilters() as $field => $value) {
            if (is_array($value)) {
                $filters[] = ['terms' => [$field => $value]];
            } else {
                $filters[] = ['term' => [$field => $value]];
            }
        }

        return $filters;
    }
}

class SearchAnalyzer
{
    private array $analyzers = [];
    private array $normalizers = [];

    public function analyze(SearchQuery $query): AnalyzedQuery
    {
        $searchText = $this->analyzeSearchText($query->getSearchText());
        $filters = $this->analyzeFilters($query->getFilters());
        $suggestions = $this->generateSuggestions($searchText);

        return new AnalyzedQuery(
            $searchText,
            $filters,
            $suggestions,
            $query->getOffset(),
            $query->getLimit()
        );
    }

    public function analyzeDocument(Searchable $document): AnalyzedDocument
    {
        $analyzed = [];

        foreach ($document->getSearchableFields() as $field => $value) {
            $analyzed[$field] = $this->analyzeField($field, $value);
        }

        return new AnalyzedDocument(
            $document->getSearchableId(),
            $analyzed,
            $document->getSearchableType()
        );
    }

    private function analyzeSearchText(string $text): string
    {
        $normalized = $this->normalize($text);
        
        foreach ($this->analyzers as $analyzer) {
            $normalized = $analyzer->analyze($normalized);
        }

        return $normalized;
    }

    private function analyzeField(string $field, $value): array
    {
        return [
            'raw' => $value,
            'analyzed' => $this->analyzeSearchText((string) $value)
        ];
    }
}

interface Searchable
{
    public function getSearchableId(): string;
    public function getSearchableType(): string;
    public function getSearchableFields(): array;
}

class SearchQuery
{
    private string $searchText;
    private array $filters;
    private int $offset;
    private int $limit;

    public function __construct(
        string $searchText,
        array $filters = [],
        int $offset = 0,
        int $limit = 10
    ) {
        $this->searchText = $searchText;
        $this->filters = $filters;
        $this->offset = $offset;
        $this->limit = $limit;
    }

    public function getSearchText(): string
    {
        return $this->searchText;
    }

    public function getFilters(): array
    {
        return $this->filters;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function getLimit(): int
    {
        return $this->limit;
    }
}

class SearchResult
{
    private array $items;
    private int $total;
    private array $facets;
    private array $suggestions;

    public function __construct(
        array $items,
        int $total,
        array $facets = [],
        array $suggestions = []
    ) {
        $this->items = $items;
        $this->total = $total;
        $this->facets = $facets;
        $this->suggestions = $suggestions;
    }

    public function getItems(): array
    {
        return $this->items;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function getFacets(): array
    {
        return $this->facets;
    }

    public function getSuggestions(): array
    {
        return $this->suggestions;
    }
}
