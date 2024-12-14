<?php

namespace App\Core\Contracts\Repositories;

use App\Core\Models\Post;
use Illuminate\Pagination\LengthAwarePaginator;

interface PostRepositoryInterface
{
    public function getPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator;
    
    public function findById(int $id, array $relations = []): Post;
    
    public function findBySlug(string $slug, array $relations = []): Post;
    
    public function create(array $data): Post;
    
    public function update(int $id, array $data): Post;
    
    public function delete(int $id): bool;
    
    public function getByCategory(int $categoryId, int $perPage = 15): LengthAwarePaginator;
    
    public function getByTag(int $tagId, int $perPage = 15): LengthAwarePaginator;
}
