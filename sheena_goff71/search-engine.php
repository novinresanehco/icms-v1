<?php

namespace App\Core\Search\Engines;

use App\Core\Search\Query\SearchQuery;
use App\Core\Search\Results\SearchResults;

interface SearchEngineInterface
{
    public function search(SearchQuery $query): SearchResults;
    
    public function index(string $type, string|int $id, array $document): bool;
    
    public function delete(string $type, string|int $id): bool;
    
    public function bulkIndex(array $documents): bool;
    
    public function suggest(string $query): array;
    
    public function flush(string $type): bool;
}

class ElasticsearchEngine implements SearchEngineInterface
{
    public function __construct(
        private \Elasticsearch\Client $client,
        private string $index
    ) {}

    public function search(SearchQuery $query): SearchResults
    {
        $params = [
            'index' => $this->index,
            'body'  => [
                'query' => $this->buildQuery($query),
                'size' => $query->limit,
                'from' => ($query->page - 1) * $query->limit,
            ]
        ];

        if ($query->facets) {
            $params['body']['aggs'] = $this->buildAggregations($query->facets);
        }

        if ($query->sort) {
            $params['body']['sort'] = $this->buildSort($query->sort);
        }

        $response = $this->client->search($params);

        return new SearchResults(
            items: $this->formatHits($response['hits']['hits']),
            total: $response['hits']['total']['value'],
            facets: $this->formatAggregations($response['aggregations'] ?? [])
        );
    }

    public function index(string $type, string|int $id, array $document): bool
    {
        try {
            $this->client->index([
                'index' => $this->index,
                'id'    => $id,
                'body'  => array_merge($document, ['_type' => $type])
            ]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function delete(string $type, string|int $id): bool
    {
        try {
            $this->client->delete([
                'index' => $this->index,
                'id'    => $id
            ]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function bulkIndex(array $documents): bool
    {
        $params = ['body' => []];

        foreach ($documents as $document) {
            $params['body'][] = [
                'index' => [
                    '_index' => $this->index,
                    '_id'    => $document['id']
                ]
            ];
            
            $params['body'][] = array_merge(
                $document['body'],
                ['_type' => $document['type']]
            );
        }

        try {
            $this->client->bulk($params);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function suggest(string $query): array
    {
        $response = $this->client->search([
            'index' => $this->index,
            'body'  => [
                'suggest' => [
                    'text' => $query,
                    'term' => [
                        'field' => 'title',
                        'suggest_mode' => 'popular',
                        'size' => 5
                    ]
                ]
            ]
        ]);

        return collect($response['suggest']['term'][0]['options'])
            ->pluck('text')
            ->all();
    }

    public function flush(string $type): bool
    {
        try {
            $this->client->deleteByQuery([
                'index' => $this->index,
                'body'  => [
                    'query' => [
                        'term' => ['_type' => $type]
                    ]
                ]
            ]);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function buildQuery(SearchQuery $query): array
    {
        return [
            'bool' => [
                'must' => [
                    'multi_match' => [
                        'query' => $query->term,
                        'fields' => $query->fields,
                        'type' => 'best_fields'
                    ]
                ],
                'filter' => $this->buildFilters($query->filters)
            ]
        ];
    }

    protected function buildFilters(array $filters): array
    {
        return collect($filters)->map(function ($value, $field) {
            return ['term' => [$field => $value]];
        })->values()->all();
    }

    protected function buildAggregations(array $facets): array
    {
        return collect($facets)->mapWithKeys(function ($facet) {
            return [$facet => ['terms' => ['field' => $facet]]];
        })->all();
    }

    protected function buildSort(array $sort): array
    {
        return collect($sort)->map(function ($direction, $field) {
            return [$field => ['order' => $direction]];
        })->values()->all();
    }

    protected function formatHits(array $hits): array
    {
        return collect($hits)->map(function ($hit) {
            return array_merge(
                $hit['_source'],
                ['_score' => $hit['_score']]
            );
        })->all();
    }

    protected function formatAggregations(array $aggregations): array
    {
        return collect($aggregations)->map(function ($aggregation) {
            return collect($aggregation['buckets'])->map(function ($bucket) {
                return [
                    'value' => $bucket['key'],
                    'count' => $bucket['doc_count']
                ];
            })->all();
        })->all();
    }
}
