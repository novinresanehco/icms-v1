<?php

namespace App\Core\Search\Contracts;

interface SearchServiceInterface
{
    public function search(string $query, array $filters = [], array $options = []): SearchResults;
    public function indexDocument(Searchable $document): void;
    public function updateDocument(Searchable $document): void;
    public function removeDocument(Searchable $document): void;
    public function reindexAll(): void;
}

interface Searchable
{
    public function toSearchArray(): array;
    public function getSearchableId(): string;
    public function getSearchableType(): string;
}

namespace App\Core\Search\Services;

class SearchService implements SearchServiceInterface
{
    protected IndexManager $indexManager;
    protected SearchEngine $searchEngine;
    protected ResultsFormatter $formatter;

    public function __construct(
        IndexManager $indexManager,
        SearchEngine $searchEngine,
        ResultsFormatter $formatter
    ) {
        $this->indexManager = $indexManager;
        $this->searchEngine = $searchEngine;
        $this->formatter = $formatter;
    }

    public function search(string $query, array $filters = [], array $options = []): SearchResults
    {
        $searchParams = $this->buildSearchParams($query, $filters, $options);
        $rawResults = $this->searchEngine->search($searchParams);
        
        return $this->formatter->format($rawResults, $options);
    }

    public function indexDocument(Searchable $document): void
    {
        $indexData = $this->prepareDocumentData($document);
        $this->indexManager->index($indexData);
    }

    public function updateDocument(Searchable $document): void
    {
        $indexData = $this->prepareDocumentData($document);
        $this->indexManager->update($indexData);
    }

    public function removeDocument(Searchable $document): void
    {
        $this->indexManager->remove(
            $document->getSearchableType(),
            $document->getSearchableId()
        );
    }

    public function reindexAll(): void
    {
        $this->indexManager->recreateIndex();
        
        foreach ($this->getSearchableModels() as $model) {
            $model::chunk(100, function ($documents) {
                foreach ($documents as $document) {
                    $this->indexDocument($document);
                }
            });
        }
    }

    protected function prepareDocumentData(Searchable $document): array
    {
        return [
            'id' => $document->getSearchableId(),
            'type' => $document->getSearchableType(),
            'body' => $document->toSearchArray(),
            'timestamp' => now()
        ];
    }

    protected function buildSearchParams(string $query, array $filters, array $options): array
    {
        return [
            'query' => $this->buildQuery($query),
            'filters' => $this->buildFilters($filters),
            'sort' => $options['sort'] ?? ['_score' => 'desc'],
            'page' => $options['page'] ?? 1,
            'per_page' => $options['per_page'] ?? 15,
            'highlight' => $options['highlight'] ?? true
        ];
    }
}

namespace App\Core\Search\Services;

class SearchEngine
{
    protected Builder $queryBuilder;
    protected array $indices;

    public function search(array $params): array
    {
        $query = $this->queryBuilder
            ->initializeQuery()
            ->addQueryString($params['query'])
            ->addFilters($params['filters'])
            ->addSort($params['sort'])
            ->setPagination($params['page'], $params['per_page']);

        if ($params['highlight']) {
            $query->enableHighlighting();
        }

        return $query->execute();
    }

    public function suggest(string $term): array
    {
        return $this->queryBuilder
            ->initializeSuggestion()
            ->addTerm($term)
            ->execute();
    }

    public function analyze(string $text): array
    {
        return $this->queryBuilder
            ->analyzeText($text);
    }
}

namespace App\Core\Search\Services;

class IndexManager
{
    protected array $indices;
    protected array $settings;
    protected array $mappings;

    public function index(array $document): void
    {
        $index = $this->getIndex($document['type']);
        $index->index([
            'index' => $index->getName(),
            'id' => $document['id'],
            'body' => array_merge($document['body'], [
                'indexed_at' => now()->toIso8601String()
            ])
        ]);
    }

    public function update(array $document): void
    {
        $index = $this->getIndex($document['type']);
        $index->update([
            'index' => $index->getName(),
            'id' => $document['id'],
            'body' => [
                'doc' => array_merge($document['body'], [
                    'updated_at' => now()->toIso8601String()
                ])
            ]
        ]);
    }

    public function remove(string $type, string $id): void
    {
        $index = $this->getIndex($type);
        $index->delete([
            'index' => $index->getName(),
            'id' => $id
        ]);
    }

    public function recreateIndex(): void
    {
        foreach ($this->indices as $index) {
            if ($index->exists()) {
                $index->delete();
            }

            $index->create([
                'settings' => $this->settings,
                'mappings' => $this->mappings
            ]);
        }
    }

    protected function getIndex(string $type): Index
    {
        if (!isset($this->indices[$type])) {
            throw new IndexNotFoundException("Index not found for type: {$type}");
        }

        return $this->indices[$type];
    }
}

namespace App\Core\Search\Services;

class QueryBuilder
{
    protected array $query = [];
    protected array $filters = [];
    protected array $sort = [];
    protected array $pagination = [];

    public function initializeQuery(): self
    {
        $this->query = [
            'bool' => [
                'must' => [],
                'filter' => [],
                'should' => [],
                'must_not' => []
            ]
        ];

        return $this;
    }

    public function addQueryString(string $queryString): self
    {
        $this->query['bool']['must'][] = [
            'multi_match' => [
                'query' => $queryString,
                'fields' => ['title^3', 'content', 'tags^2'],
                'type' => 'best_fields',
                'fuzziness' => 'AUTO'
            ]
        ];

        return $this;
    }

    public function addFilters(array $filters): self
    {
        foreach ($filters as $field => $value) {
            $this->addFilter($field, $value);
        }

        return $this;
    }

    public function addSort(array $sort): self
    {
        $this->sort = $sort;
        return $this;
    }

    public function setPagination(int $page, int $perPage): self
    {
        $this->pagination = [
            'from' => ($page - 1) * $perPage,
            'size' => $perPage
        ];

        return $this;
    }

    public function enableHighlighting(): self
    {
        $this->query['highlight'] = [
            'fields' => [
                'title' => [
                    'number_of_fragments' => 0
                ],
                'content' => [
                    'number_of_fragments' => 3,
                    'fragment_size' => 150
                ]
            ]
        ];

        return $this;
    }

    protected function addFilter(string $field, $value): void
    {
        if (is_array($value)) {
            $this->query['bool']['filter'][] = [
                'terms' => [
                    $field => $value
                ]
            ];
        } else {
            $this->query['bool']['filter'][] = [
                'term' => [
                    $field => $value
                ]
            ];
        }
    }
}

namespace App\Core\Search\Services;

class ResultsFormatter
{
    protected array $formatters = [];

    public function format(array $rawResults, array $options = []): SearchResults
    {
        $hits = $rawResults['hits']['hits'] ?? [];
        $total = $rawResults['hits']['total']['value'] ?? 0;

        $results = [];
        foreach ($hits as $hit) {
            $results[] = $this->formatHit($hit, $options);
        }

        return new SearchResults([
            'results' => $results,
            'total' => $total,
            'page' => $options['page'] ?? 1,
            'per_page' => $options['per_page'] ?? 15
        ]);
    }

    protected function formatHit(array $hit, array $options): array
    {
        $source = $hit['_source'];
        $type = $source['type'] ?? 'default';

        $formatted = [
            'id' => $hit['_id'],
            'type' => $type,
            'score' => $hit['_score'],
            'data' => $source
        ];

        if (isset($hit['highlight'])) {
            $formatted['highlights'] = $hit['highlight'];
        }

        if (isset($this->formatters[$type])) {
            $formatted = $this->formatters[$type]->format($formatted);
        }

        return $formatted;
    }
}
