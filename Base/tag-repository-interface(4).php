<?php

namespace App\Core\Repositories\Contracts;

use App\Models\Tag;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface TagRepositoryInterface
{
    public function find(int $id): ?Tag;
    
    public function findBySlug(string $slug): ?Tag;
    
    public function paginate(int $perPage = 15): LengthAwarePaginator;
    
    public function create(array $data): Tag;
    
    public function update(int $id, array $data): Tag;
    
    public function delete(int $id): bool;
    
    public function findOrCreate(string $name): Tag;
    
    public function syncContentTags(int $contentId, array $tagIds): bool;
    
    public function getPopular(int $limit = 10): Collection;
    
    public function search(string $term): Collection;
    
    public function getRelated(int $tagId, int $limit = 5): Collection;
    
    public function getByType(string $type): Collection;
}
