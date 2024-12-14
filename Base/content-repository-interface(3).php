<?php

namespace App\Repositories\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface ContentRepositoryInterface
{
    public function create(array $data): ?int;
    
    public function update(int $contentId, array $data): bool;
    
    public function delete(int $contentId): bool;
    
    public function get(int $contentId, array $relations = []): ?array;
    
    public function getBySlug(string $slug, array $relations = []): ?array;
    
    public function getAllPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator;
    
    public function getPublishedByType(string $type, int $limit = 10): Collection;
    
    public function search(string $query, array $types = [], int $perPage = 15): LengthAwarePaginator;
    
    public function publishContent(int $contentId): bool;
    
    public function unpublishContent(int $contentId): bool;
    
    public function updateMetadata(int $contentId, array $metadata): bool;
}
