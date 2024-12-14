<?php

namespace App\Repositories\Contracts;

use Illuminate\Support\Collection;

interface TagRepositoryInterface
{
    public function create(array $data): ?int;
    
    public function update(int $tagId, array $data): bool;
    
    public function delete(int $tagId): bool;
    
    public function get(int $tagId): ?array;
    
    public function getBySlug(string $slug): ?array;
    
    public function getAll(): Collection;
    
    public function findOrCreate(string $name): int;
    
    public function getPopular(int $limit = 10): Collection;
    
    public function getRelated(int $tagId, int $limit = 5): Collection;
}
