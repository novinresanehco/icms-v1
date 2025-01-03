<?php

namespace App\Core\Search\Requests;

class SearchRequest
{
    public function __construct(
        public readonly string $query,
        public readonly array $types = [],
        public readonly array $filters = [],
        public readonly ?User $user = null
    ) {}
}

class IndexRequest
{
    public function __construct(
        public readonly string $id,
        public readonly string $content,
        public readonly array $metadata = [],
        public readonly ?User $user = null
    ) {}
}

class UpdateRequest
{
    public function __construct(
        public readonly string $id,
        public readonly string $content,
        public readonly array $metadata = [],
        public readonly ?User $user = null
    ) {}
}

class DeleteRequest
{
    public function __construct(
        public readonly string $id,
        public readonly ?User $user = null
    ) {}
}

class OptimizeRequest
{
    public function __construct(
        public readonly ?User $user = null
    ) {}
}

class SearchResult
{
    public function __construct(
        public readonly array $results,
        public readonly int $total,
        public readonly float $time
    ) {}
}

