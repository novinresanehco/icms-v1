<?php

namespace App\Core\Services\Contracts;

use App\Core\Models\Post;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface PostServiceInterface
{
    public function getPost(int $id): ?Post;
    
    public function getPostBySlug(string $slug): ?Post;
    
    public function getLatestPosts(int $limit = 10): Collection;
    
    public function getPopularPosts(int $limit = 10): Collection;
    
    public function getFeaturedPosts(int $limit = 5): Collection;
    
    public function getCategoryPosts(int $categoryId, int $perPage = 15): LengthAwarePaginator;
    
    public function getTagPosts(int $tagId, int $perPage = 15): LengthAwarePaginator;
    
    public function getAuthorPosts(int $authorId, int $perPage = 15): LengthAwarePaginator;
    
    public function searchPosts(string $query, int $perPage = 15): LengthAwarePaginator;
    
    public function createPost(array $data): Post;
    
    public function updatePost(int $id, array $data): Post;
    
    public function deletePost(int $id): bool;
    
    public function restorePost(int $id): bool;
    
    public function syncPostTags(Post $post, array $tagIds): void;
    
    public function syncPostCategories(Post $post, array $categoryIds): void;
}
