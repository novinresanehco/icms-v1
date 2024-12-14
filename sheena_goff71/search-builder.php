<?php

namespace App\Core\Search\Builders;

use App\Core\Search\DTOs\SearchRequest;
use App\Core\Search\Query\SearchQuery;

class SearchQueryBuilder
{
    public function build(SearchRequest $request): SearchQuery
    {
        return new SearchQuery(
            term: $request->query,
            fields: $this->getSearchFields($request),
            filters: $this->buildFilters($request),
            facets: $request->facets,
            sort: $this->buildSort($request),
            page: $request->page,
            limit: $request->limit
        );
    }

    protected function getSearchFields(SearchRequest $request): array
    {
        $defaultFields = [
            'title^3',
            'description^2',
            'content'
        ];

        return $request->fields ?: $defaultFields;
    }

    protected function buildFilters(SearchRequest $request): array
    {
        $filters = [];

        if ($request->type) {
            $filters['_type'] = $request->type;
        }

        if ($request->status) {
            $filters['status'] = $request->status;
        }

        if ($request->filters) {
            $filters = array_merge($filters, $request->filters);
        }

        return $filters;
    }

    protected function buildSort(SearchRequest $request): array
    {
        if (!$request->sort) {
            return ['_score' => 'desc'];
        }

        return collect(explode(',', $request->sort))
            ->mapWithKeys(function ($sort) {
                [$field, $direction] = str_contains($sort, ':') 
                    ? explode(':', $sort) 
                    : [$sort, 'asc'];
                return [$field => $direction];
            })
            ->all();
    }
}
