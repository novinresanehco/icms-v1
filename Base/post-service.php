<?php

namespace App\Core\Services;

use App\Core\Models\Post;
use App\Core\Services\Contracts\PostServiceInterface;
use App\Core\Repositories\Contracts\PostRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Pagination\LengthAwarePaginator;

class PostService implements PostServiceInterface
{
    public function __construct(
        private PostRepositoryInterface $repository
    ) {}

    public function getPost(int $id): ?Post
    {
        return Cache::tags(['posts'])->remember(
            "posts.{$id}",
            now()->addHour(),
            fn() => $this->repository->findById($id)
        );
    }

    public function getPostBySlug(string $slug): ?Post
    {
        return Cache::tags(['posts'])->remember(
            "posts.slug.{$slug}",
            now()->addHour(),
            fn() => $this->repository->findBySlug($slug)
        );
    }

    public function getLatestPosts(int $limit = 10): Collection
    {
        return Cache::tags(['posts'])->remember(
            "posts.latest.{$limit}",
            now()->addHour(),
            fn() => $this->repository->getLatest($limit)
        );
    }

    public function getPopularPosts(int $limit = 10): Collection
    {
        return Cache::tags(['posts'])->remember(
            "posts.popular.{$limit}",
            now()->addHour(),
            fn() => $this->repository->getPopular($limit)
        );
    }

    public function getFeaturedPosts(int $limit = 5): Collection
    {
        return Cache::tags(['posts'])->remember(
            "posts.featured.{$limit}",
            now()->addHour(),
            fn() => $this->repository->getFeatured($limit)
        );
    }

    public function getCategoryPosts(int $categoryId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->getByCategory($categoryId, $perPage);
    }

    public function getTagPosts(int $tagId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->getByTag($tagId, $perPage);
    }

    public function getAuthorPosts(int $authorId, int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->getByAuthor($authorId, $perPage);
    }

    public function searchPosts(string $query, int $perPage = 15): LengthAwarePaginator
    {
        return $this->repository->search($query, $perPage);
    }

    public function createPost(array $data): Post
    {
        $post = $this->repository->store($data);
        Cache::tags(['posts'])->flush();
        return $post;
    }

    public function updatePost(int $id, array $data): Post
    {
        $post = $this->repository->update($id, $data);
        Cache::tags(['posts'])->flush();
        return $post;
    }

    public function deletePost(int $id): bool
    {
        $result = $this->repository->delete($id);
        Cache::tags(['posts'])->flush();
        return $result;
    }

    public function restorePost(int $id): bool
    {
        $result = $this->repository->restore($id);
        Cache::tags(['posts'])->flush();
        return $result;
    }

    public function syncPostTags(Post $post, array $tagIds): void
    {
        $this->repository->syncTags($post, $tagIds);
        Cache::tags(['posts'])->flush();
    }

    public function syncPostCategories(Post $post, array $categoryIds): void
    {
        $this->repository->syncCategories($post, $categoryIds);
        Cache::tags(['posts'])->flush();
    }
}
