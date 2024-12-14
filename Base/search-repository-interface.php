<?php

namespace App\Core\Repositories\Contracts;

use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface SearchRepositoryInterface extends RepositoryInterface
{
    public function search(string $query, array $filters = [], int $perPage = 15): LengthAwarePaginator;
    
    public function indexContent(string $type, int $id, array $data): bool;
    
    public function removeFromIndex(string $type, int $id): bool;
    
    public function getSuggestions(string $query, int $limit = 5): Collection;
    
    public function getPopularSearches(int $limit = 10): Collection;
    
    public function logSearch(string $query, ?int $userId = null): void;
    
    public function reindexAll(): int;
}
