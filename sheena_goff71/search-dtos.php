<?php

namespace App\Core\Search\DTOs;

class SearchRequest
{
    public function __construct(
        public readonly string $query,
        public readonly ?string $type = null,
        public readonly ?string $status = null,
        public readonly ?array $fields = null,
        public readonly ?array $filters = null,
        public readonly ?array $facets = null,
        public readonly ?string $sort = null,
        public readonly int $page = 1,
        public readonly int $limit = 20,
        public readonly bool $suggestions = false
    ) {}

    public function suggestionsEnabled(): bool
    {
        return $this->suggestions && strlen($this->query) >= 3;
    }
}

class SearchResponse
{
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly array $facets = [],
        public readonly array $suggestions = []
    ) {}

    public function hasResults(): bool
    {
        return !empty($this->items);
    }

    public function getTotalPages(int $perPage): int
    {
        return ceil($this->total / $perPage);
    }
}

namespace App\Core\Search\Query;

class SearchQuery
{
    public function __construct(
        public readonly string $term,
        public readonly array $fields,
        public readonly array $filters = [],
        public readonly array $facets = [],
        public readonly array $sort = [],
        public readonly int $page = 1,
        public readonly int $limit = 20
    ) {}
}
