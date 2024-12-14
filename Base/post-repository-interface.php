<?php

namespace App\Core\Repositories\Contracts;

use App\Core\Models\Post;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface PostRepositoryInterface
{
    public function findById(int $id): ?Post;
    
    public function findBySlug(string $slug): ?Post;
    
    public function getLatest(int $limit = 10): Collection;
    
    public function getPopular(int $limit = 10): Collection;
    
    public function getFeatured(int $limit = 5): Collection;
    
    public function getByCategory(int $categoryId, int $perPage = 15): LengthAwarePaginator;
    
    public function getByTag(int $tagId, int $perPage = 15): LengthAwarePaginator;
    
    public function getByAuthor(int $authorId, int $perPage = 15): LengthAwarePaginator;
    
    public function search(string $query, int $perPage = 15): LengthAwarePaginator;
    
    public function store(array $data): Post;
    
    public function update(int $id, array $data): Post;
    
    public function delete(int $id): bool;
    
    public function restore(int $id): bool;
    
    public function syncTags(Post $post, array $tagIds): void;
    
    public function syncCategories(Post $post, array $categoryIds): void;
}
