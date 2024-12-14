<?php

namespace App\Core\Search\Services;

use App\Core\Search\Engines\SearchEngineInterface;
use App\Core\Search\Builders\SearchQueryBuilder;
use App\Core\Search\DTOs\{SearchRequest, SearchResponse};
use Illuminate\Support\Collection;

class SearchService
{
    public function __construct(
        private SearchEngineInterface $engine,
        private SearchQueryBuilder $queryBuilder
    ) {}

    public function search(SearchRequest $request): SearchResponse
    {
        $query = $this->queryBuilder->build($request);
        $results = $this->engine->search($query);

        return new SearchResponse(
            items: $results->items(),
            total: $results->total(),
            facets: $results->facets(),
            suggestions: $this->getSuggestions($request)
        );
    }

    public function index(Searchable $model): bool
    {
        return $this->engine->index(
            $model->getSearchableType(),
            $model->getSearchableId(),
            $model->toSearchableArray()
        );
    }

    public function delete(Searchable $model): bool
    {
        return $this->engine->delete(
            $model->getSearchableType(),
            $model->getSearchableId()
        );
    }

    public function bulkIndex(Collection $models): bool
    {
        $documents = $models->map(function ($model) {
            return [
                'type' => $model->getSearchableType(),
                'id' => $model->getSearchableId(),
                'body' => $model->toSearchableArray()
            ];
        });

        return $this->engine->bulkIndex($documents->all());
    }

    protected function getSuggestions(SearchRequest $request): array
    {
        if (!$request->suggestionsEnabled()) {
            return [];
        }

        return $this->engine->suggest($request->query);
    }
}
